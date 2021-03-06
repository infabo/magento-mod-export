﻿<?php
class OpsWay_Exportproducts_Model_Observer extends Mage_Adminhtml_Controller_Action
{    
    protected $_exportPageSize = 1000;

    /**
     * Main method, that starts export
     * @return bool
     */
    public function initExport() {

        $exportIsRunning = $this->_exportAlreadyRunning();
        
        if(!$exportIsRunning) {
            $jobsToExport = $this->_getNewExportJobs();
            
            if(count($jobsToExport)) {
                Mage::log("--- " . count($jobsToExport) . " new job(s) to process ---", null, 'export_products.log');
                try {
                    $this->_runExport($jobsToExport[0]);
                } catch (Mage_Core_Exception $e) {
                    Mage::log("Error running export: " . $e->getMessage(), null, 'export_products.log');
                    $this->_getSession()->addError($e->getMessage());
                } catch (Exception $e) {
                    Mage::logException($e);
                    $this->_getSession()->addError($this->__('Can`t run export'));
                }
            } else {
                //no new jobs to export
                return false;
            }
        } else {
            //another process running
            return false;
        }
    }

    /**
     * Checks if there are already processing jobs now
     * @return array of processing jobs
     */
    protected function _exportAlreadyRunning() {
        $queueModel = Mage::getModel('OpsWay_Exportproducts/Queue');
        $queueCollection = $queueModel->getCollection()
                                      ->addFieldToFilter('status', array(
                                            array('eq' => 'processing')
                                        ));
        return $queueCollection->getData();
    }

    /**
     * Checks if there are new jobs to process
     * @return array of jobs to process
     */
    protected function _getNewExportJobs() {
        $queueModel = Mage::getModel('OpsWay_Exportproducts/Queue');
        $queueCollection = $queueModel->getCollection()
                                      ->addFieldToFilter('status', array(
                                            array('eq' => 'pending')
                                        ))
                                      ->addOrder('add_date','ASC');
        return $queueCollection->getData();
    }

    /**
     * Contains export logic, receives data to export
     * @param  array $export data to export 
     * @return
     */
    protected function _runExport($export) {
        $start_date = now();
        $this->_updateStatus($export['id'], 'processing', $start_date, null);

        try {
            $csv = $this->_exportFile($export);
        } catch (Mage_Core_Exception $e) {
            Mage::log("Export error: " . $e->getMessage(), null, 'export_products.log');
            $this->_getSession()->addError($e->getMessage());
            $this->_updateStatus($export['id'], 'error', $start_date, now());
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('Export error'));
        }
        
        if($csv) {
            try {
                $this->_saveCsv($csv, $export);
                $stop_date = now();
                $this->_updateStatus($export['id'], 'finished', $start_date, $stop_date);
                $this->_getSession()->addSuccess($this->__($export['file'] . ' exported successfully', null, 'export_products.log'));
                Mage::log("--- Export successfully finished ---", null, 'export_products.log');
            } catch (Mage_Core_Exception $e) {
                Mage::log("Error saving csv: " . $e->getMessage(), null, 'export_products.log');
                $this->_getSession()->addError($e->getMessage());
            } catch (Exception $e) {
                Mage::logException($e);
                $this->_getSession()->addError($this->__('Can`t save csv'));
            }
        }
    }

    /**
     * Helper function to update jobs status, according to current export process step
     * @param  int $id         is of job to work with
     * @param  string $status     current status
     * @param  date $start_date 
     * @param  date $stop_date  
     * @return 
     */
    protected function _updateStatus($id, $status, $start_date, $stop_date) {
        $queueModel = Mage::getModel('OpsWay_Exportproducts/Queue')->load($id);
        $queueModel->setData('status', $status)
                   ->setData('start_date', $start_date)
                   ->setData('stop_date', $stop_date)
                   ->save();
    }

    /**
     * Makes formatted string to export
     * @return string
     */
    protected function _exportFile($file) { 
        Mage::log("Exporting file " . $file['file'], null, 'export_products.log');

        try {

            $productsCollection = Mage::getModel('catalog/product')->getCollection()->addAttributeToSelect('*');
            $productsCollection->setPageSize($this->_exportPageSize);
         
            $pages = $productsCollection->getLastPageNumber();
            $currentPage = 1;

            $content = '';

            Mage::log("Exporting " . $pages . " page(s) by " . $this->_exportPageSize . " rows", null, 'export_products.log');
         
            do {
                $productsCollection->setCurPage($currentPage);
                $productsCollection->load();

                Mage::log("Page " . $currentPage . " data exporting...", null, 'export_products.log');
         
                foreach ($productsCollection as $_product) {
                    $content .= implode(',', $_product->getData()) . PHP_EOL;
                }
         
                $currentPage++;
                //clear collection and free memory
                $productsCollection->clear();
                
            } while ($currentPage <= $pages);

            //Adding cols titles
            $cols = array_keys($productsCollection->getFirstItem()->getData());
            $csvWithHeader = implode(',', $cols) . PHP_EOL . $content;
            
            return $csvWithHeader;

        } catch (Mage_Core_Exception $e) {
            Mage::log("Not valid parameters: " . $e->getMessage(), null, 'export_products.log');
            $this->_getSession()->addError($e->getMessage());
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('No valid data sent'));
        }
    }

    /**
     * Generates CSV file
     * @return array
     */
    protected function _saveCsv($csv, $exportData)
    {
        Mage::log("Saving csv file...", null, 'export_products.log');
        $io       = new Varien_Io_File();
        $path     = Mage::getBaseDir('var') . DS . 'export';
        $file     = $path . DS . $exportData['file'];
        $csvArray = explode(PHP_EOL, $csv);

        $io->setAllowCreateFolders(true);
        $io->open(array('path' => $path));
        $io->streamOpen($file, 'w+');
        $io->streamLock(true);

        foreach ($csvArray as $key => $row) {
            $io->streamWriteCsv(explode(',', $row));
        }

        return array(
            'type'  => 'filename',
            'value' => $file,
            'rm'    => true // can delete file after use
        );
    }
}