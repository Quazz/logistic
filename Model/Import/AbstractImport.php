<?php
/**
 * 2011-2017 PH2M
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is available
 * through the world-wide-web at this URL: http://www.opensource.org/licenses/OSL-3.0
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to agence@reflet-digital.com so we can send you a copy immediately.
 *
 * @author PH2M - contact@ph2m.com
 * @copyright 2001-2017 PH2M
 * @license http://www.opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
namespace PH2M\Logistic\Model\Import;

use FireGento\FastSimpleImport\Model\ImporterFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\Io\Ftp;
use Magento\Framework\Filesystem\Io\Sftp;
use Magento\Framework\Filesystem\File\ReadFactory;
use Magento\Store\Model\ScopeInterface;
use PH2M\Logistic\Model\Config\Source\Connectiontype;
use PH2M\Logistic\Model\Log;
use PH2M\Logistic\Model\LogFactory;
use PH2M\Logistic\Api\LogRepositoryInterface;

/**
 * Class ImportAbstract
 * @package PH2M\Logistic\Model\Import
 */
abstract class AbstractImport
{
    /**
     * @var string
     */
    protected $code = 'override_me';

    /**
     * @var array
     */
    protected $columnsToIgnore = [];

    /**
     * @var array
     */
    protected $columnsToRename = [];

    /**
     * @var array
     */
    protected $columnsFixedValues = [];

    /**
     * @var Ftp
     */
    protected $ftp;

    /**
     * @var Sftp
     */
    protected $sftp;

    /**
     * @var ReadFactory
     */
    protected $fileReaderFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Connectiontype
     */
    protected $connectionTypeSource;

    /**
     * @var array
     */
    protected $filesToImport = [];

    /**
     * @var string
     */
    protected $fieldSeparator;

    /**
     * @var string
     */
    protected $fieldEnclosure;

    /**
     * @var ImporterFactory
     */
    protected $importerFactory;

    /**
     * @var LogFactory
     */
    protected $logFactory;

    /**
     * @var LogRepositoryInterface
     */
    protected $logRepository;

    /**
     * @var array
     */
    protected $messages;

    /**
     * @var bool
     */
    protected $hasError = false;

    /**
     * AbstractImport constructor.
     * @param Ftp $ftp
     * @param Sftp $sftp
     * @param ReadFactory $fileReaderFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param Connectiontype $connectiontypeSource
     * @param ImporterFactory $importerFactory
     * @param LogFactory $logFactory
     * @param LogRepositoryInterface $logRepository
     */
    public function __construct(
        Ftp $ftp,
        Sftp $sftp,
        ReadFactory $fileReaderFactory,
        ScopeConfigInterface $scopeConfig,
        Connectiontype $connectiontypeSource,
        ImporterFactory $importerFactory,
        LogFactory $logFactory,
        LogRepositoryInterface $logRepository
    ) {
        $this->ftp                  = $ftp;
        $this->sftp                 = $sftp;
        $this->fileReaderFactory    = $fileReaderFactory;
        $this->scopeConfig          = $scopeConfig;
        $this->connectionTypeSource = $connectiontypeSource;
        $this->importerFactory      = $importerFactory;
        $this->logFactory           = $logFactory;
        $this->logRepository        = $logRepository;

        $this->messages             = [];
    }

    /**
     * @throws FileSystemException
     * @throws NotFoundException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\ValidatorException
     */
    public function process()
    {
        $this->_downloadFiles();
        $this->_importDownloadedFiles();
        $this->_reportResult();
    }

    /**
     * - Connect to distant server (FTP or SFTP)
     * - Retrieve the matching files and download them to var/logistic folder
     *
     * @throws FileSystemException
     * @throws NotFoundException
     * @throws \Magento\Framework\Exception\ValidatorException
     */
    protected function _downloadFiles()
    {
        $connection = $this->_getConnection();
        $host       = $this->_getConfig('connection', 'host');

        if ($configPort = $this->_getConfig('connection', 'port')) {
            $host .= ':' . $configPort;
        }

        $connection->open([
            'host'      => $host,
            'username'  => $this->_getConfig('connection', 'username'),
            'password'  => $this->_getConfig('connection', 'password')
        ]);

        if (!$connection->cd($this->_getConfig('import', $this->code . '_path'))) {
            throw new NotFoundException(__('Import %1 path does not exist', $this->code));
        }

        $files = $this->_getFilesList($connection);

        $this->_readFiles($connection, $files);

        $connection->close();
    }

    /**
     * @return Ftp|Sftp
     * @throws \Magento\Framework\Exception\ValidatorException
     */
    protected function _getConnection()
    {
        $connectionType = $this->_getConfig('connection', 'type');
        $this->connectionTypeSource->validateType($connectionType);

        return $this->$connectionType;
    }

    /**
     * @param Ftp|Sftp $connection
     * @return array
     */
    protected function _getFilesList($connection)
    {
        $files = $connection->rawls();

        $filePattern = $this->_getConfig('import', $this->code . '_file_pattern');

        return array_keys(array_filter($files, function($fileDetails, $fileName) use ($filePattern) {
            // It must be a file (type 1) and match the config pattern
            return $fileDetails['type'] == 1 && preg_match($filePattern, $fileName);
        }, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param Ftp|Sftp $connection
     * @param array $files
     * @throws FileSystemException
     */
    protected function _readFiles($connection, $files)
    {
        if (!count($files)) {
            return;
        }

        $pathToSaveFiles = BP . DIRECTORY_SEPARATOR . DirectoryList::VAR_DIR . DIRECTORY_SEPARATOR . 'logistic' . DIRECTORY_SEPARATOR . $this->code;

        if (!is_dir($pathToSaveFiles)) {
            mkdir($pathToSaveFiles, 0777, true);
        }

        foreach ($files as $file) {
            $filePath = $pathToSaveFiles . DIRECTORY_SEPARATOR . $file;
            if ($connection->read($file, $filePath)) {
                $this->filesToImport[] = $filePath;
            } else {
                throw new FileSystemException(__('Error while save file to %1', $filePath));
            }
        }
    }

    /**
     *
     */
    protected function _importDownloadedFiles()
    {
        if (count($this->filesToImport)) {
            $this->fieldSeparator = $this->_getConfig('general', 'field_separator');
            $this->fieldEnclosure = $this->_getConfig('general', 'field_enclosure');

            foreach ($this->filesToImport as $fileToImport) {
                $this->_importFile($fileToImport);
            }
        } else {
            $this->messages[] = 'No file found';
        }
    }

    /**
     * @param $fileToImport
     */
    protected function _importFile($fileToImport)
    {
        $start          = microtime(true);

        /** @var \Magento\Framework\Filesystem\File\Read $fileReader */
        $fileReader     = $this->fileReaderFactory->create($fileToImport, DriverPool::FILE);
        $fileName       = explode('/', $fileToImport);
        $fileName       = end($fileName);

        $header         = [];
        $isHeader       = true;
        $dataToImport   = [];

        while ($data = $fileReader->readCsv(0, $this->fieldSeparator, $this->fieldEnclosure)) {
            if ($isHeader) {
                $header = $this->_renameHeaderColumns($data);
                $isHeader = false;
                continue;
            }

            $productData = [];

            if (trim($data[0]) === '') {
                continue;
            }

            foreach ($data as $index => $value) {
                if (!array_key_exists($index, $header)) {
                    continue;
                }
                if (in_array($header[$index], $this->columnsToIgnore)) {
                    continue;
                }
                $productData[trim($header[$index])] = trim($value);
            }

            $productData = $this->_addFixedValues($productData);
            $productData = $this->_formatProductData($productData);
            $dataToImport[] = $productData;
        }

        if (count($dataToImport)) {
            try {
                /** @var \FireGento\FastSimpleImport\Model\Importer $importer */
                $importer = $this->importerFactory->create();

                $dataToImport = $this->_beforeImportData($dataToImport);
                $importer->processImport($dataToImport);

                // TODO move file to archives

                if ($importer->getValidationResult()) {
                    $end    = microtime(true);
                    $time   = $end - $start;

                    $this->messages[] = $fileName . ': ' . count($dataToImport) . ' lines imported in ' . number_format($time, 3) . ' seconds';
                } else {
                    $this->messages[] = $fileName . ': ERROR: ' . $importer->getLogTrace();
                }
            } catch (\Exception $e) {
                $this->hasError = true;
                $this->messages[] = $fileName . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Override $this->columnsToRename to rename some header columns to real product attributes codes
     *
     * @param array $header
     * @return array
     */
    protected function _renameHeaderColumns(array $header)
    {
        if (count($this->columnsToRename)) {
            foreach ($header as $key => $headerColumnName) {
                if (isset($this->columnsToRename[$headerColumnName])) {
                    $header[$key] = $this->columnsToRename[$headerColumnName];
                }
            }
        }
        return $header;
    }

    /**
     * Override this function to format a product data array
     *
     * @param array $productData
     * @return array
     */
    protected function _formatProductData(array $productData)
    {
        return $productData;
    }

    /**
     * @param array $productData
     * @return array
     */
    protected function _addFixedValues(array $productData)
    {
        return $productData + $this->columnsFixedValues;
    }

    /**
     * Override this function to format data to import before importing it
     *
     * @param $dataToImport
     * @return array
     */
    protected function _beforeImportData(array $dataToImport)
    {
        return $dataToImport;
    }

    /**
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    protected function _reportResult()
    {
        /** @var Log $log */
        $log = $this->logFactory->create();
        
        if ($this->hasError) {
            $log->setStatus(Log::STATUS_ERROR);
        } else {
            $log->setStatus(Log::STATUS_SUCCESS);
        }

        \Magento\Framework\App\ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class)->debug('a',['a' => $this->messages]);
        $log->setMessage(implode(PHP_EOL, $this->messages))
            ->setEntityType($this->code);

        $this->logRepository->save($log);
    }

    /**
     * @param $group
     * @param $field
     * @return string
     */
    protected function _getConfig($group, $field)
    {
        return $this->scopeConfig->getValue('logistic/' . $group . '/' . $field, ScopeInterface::SCOPE_STORE);
    }
}