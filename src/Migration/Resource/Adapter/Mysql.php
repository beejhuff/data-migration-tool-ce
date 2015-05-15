<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Resource\Adapter;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Ddl\Trigger;
use Migration\Resource\Document;

/**
 * Mysql adapter
 */
class Mysql implements \Migration\Resource\AdapterInterface
{
    const BACKUP_DOCUMENT_PREFIX = 'migration_backup_';
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $resourceAdapter;

    /**
     * @var \Magento\Framework\DB\Ddl\Trigger
     */
    protected $triggerFactory;

    /**
     * @var string
     */
    protected $schemaName  = null;

    /**
     * @var array
     */
    protected $triggers = [];

    /**
     * @param \Magento\Framework\DB\Adapter\Pdo\MysqlFactory $adapterFactory
     * @param \Magento\Framework\DB\Ddl\TriggerFactory $triggerFactory
     * @param array $config
     */
    public function __construct(
        \Magento\Framework\DB\Adapter\Pdo\MysqlFactory $adapterFactory,
        \Magento\Framework\DB\Ddl\TriggerFactory $triggerFactory,
        array $config
    ) {
        $configData['config'] = $config;
        $this->resourceAdapter = $adapterFactory->create($configData);
        $this->resourceAdapter->query('SET FOREIGN_KEY_CHECKS=0;');
        $this->triggerFactory = $triggerFactory;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentStructure($documentName)
    {
        return $this->resourceAdapter->describeTable($documentName);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentList()
    {
        return $this->resourceAdapter->listTables();
    }

    /**
     * @inheritdoc
     */
    public function getRecordsCount($documentName)
    {
        $select = $this->resourceAdapter->select();
        $select->from($documentName, 'COUNT(*)');
        $result = $this->resourceAdapter->fetchOne($select);
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function loadPage($documentName, $pageNumber, $pageSize)
    {
        $select = $this->resourceAdapter->select();
        $select->from($documentName, '*')
            ->limit($pageSize, $pageNumber * $pageSize);
        $result = $this->resourceAdapter->fetchAll($select);
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function insertRecords($documentName, $records, $updateOnDuplicate = false)
    {
        $this->resourceAdapter->rawQuery("SET @OLD_INSERT_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        if ($updateOnDuplicate) {
            $result = $this->resourceAdapter->insertOnDuplicate($documentName, $records);
        } else {
            $result = $this->resourceAdapter->insertMultiple($documentName, $records);
        }
        $this->resourceAdapter->rawQuery("SET SQL_MODE=IFNULL(@OLD_INSERT_SQL_MODE,'')");

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function deleteAllRecords($documentName)
    {
        $this->resourceAdapter->truncateTable($documentName);
    }

    /**
     * @inheritdoc
     */
    public function deleteRecords($documentName, $idKey, $ids)
    {
        $ids = implode("','", $ids);
        $this->resourceAdapter->delete($documentName, "$idKey IN ('$ids')");
    }

    /**
     * @inheritdoc
     */
    public function loadChangedRecords($documentName, $deltaLogName, $idKey, $pageNumber, $pageSize)
    {
        $select = $this->resourceAdapter->select();
        $select->from($deltaLogName, [])
            ->join($documentName, "$documentName.$idKey = $deltaLogName.$idKey", '*')
            ->where("`operation` in ('INSERT', 'UPDATE')")
            ->limit($pageSize, $pageNumber * $pageSize);
        $result = $this->resourceAdapter->fetchAll($select);
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function loadDeletedRecords($deltaLogName, $idKey, $pageNumber, $pageSize)
    {
        $select = $this->resourceAdapter->select();
        $select->from($deltaLogName, [$idKey])
            ->where("`operation` = 'DELETE'")
            ->limit($pageSize, $pageNumber * $pageSize);
        $result = $this->resourceAdapter->fetchCol($select);
        return $result;
    }

    /**
     * Load data from DB Select
     *
     * @param \Magento\Framework\DB\Select $select
     * @return array
     */
    public function loadDataFromSelect($select)
    {
        return $this->resourceAdapter->fetchAll($select);
    }

    /**
     * Get DB Select
     *
     * @return \Magento\Framework\DB\Select
     */
    public function getSelect()
    {
        return $this->resourceAdapter->select();
    }

    /**
     * @param string $table
     * @param string $newTableName
     * @return Table
     */
    public function getTableDdlCopy($table, $newTableName)
    {
        return $this->resourceAdapter->createTableByDdl($table, $newTableName);
    }

    /**
     * @param Table $tableDdl
     * @return void
     */
    public function createTableByDdl($tableDdl)
    {
        $this->resourceAdapter->dropTable($tableDdl->getName());
        $this->resourceAdapter->createTable($tableDdl);
        $this->resourceAdapter->resetDdlCache($tableDdl->getName());
    }

    /**
     * Updates document rows with specified data based on a WHERE clause
     *
     * @param mixed $document
     * @param array $bind
     * @param mixed $where
     * @return int
     */
    public function updateDocument($document, array $bind, $where = '')
    {
        return $this->resourceAdapter->update($document, $bind, $where);
    }

    /**
     * @inheritdoc
     */
    public function updateChangedRecords($document, $data)
    {
        return $this->resourceAdapter->insertOnDuplicate($document, $data);
    }

    /**
     * @inheritdoc
     */
    public function backupDocument($documentName)
    {
        $backupTableName = self::BACKUP_DOCUMENT_PREFIX . $documentName;
        $tableCopy = $this->getTableDdlCopy($documentName, $backupTableName);
        if (!$this->resourceAdapter->isTableExists($backupTableName)) {
            $this->createTableByDdl($tableCopy);
            $select = $this->resourceAdapter->select()->from($documentName);
            $query = $this->resourceAdapter->insertFromSelect($select, $tableCopy->getName());
            $this->resourceAdapter->query($query);
        }
    }

    /**
     * @inheritdoc
     */
    public function rollbackDocument($documentName)
    {
        $backupTableName = self::BACKUP_DOCUMENT_PREFIX . $documentName;
        if ($this->resourceAdapter->isTableExists($backupTableName)) {
            $this->resourceAdapter->truncateTable($documentName);
            $select = $this->resourceAdapter->select()->from($backupTableName);
            $query = $this->resourceAdapter->insertFromSelect($select, $documentName);
            $this->resourceAdapter->query($query);
            $this->resourceAdapter->dropTable($backupTableName);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteBackup($documentName)
    {
        $backupTableName = self::BACKUP_DOCUMENT_PREFIX . $documentName;
        if ($this->resourceAdapter->isTableExists($backupTableName)) {
            $this->resourceAdapter->dropTable($backupTableName);
        }
    }

    /**
     * Create delta for specified table
     *
     * @param string $documentName
     * @param string $deltaLogName
     * @param string $idKey
     * @return void
     */
    public function createDelta($documentName, $deltaLogName, $idKey)
    {
        if (!$this->resourceAdapter->isTableExists($deltaLogName)) {
            $triggerTable = $this->resourceAdapter->newTable($deltaLogName)
                ->addColumn(
                    $idKey,
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false, 'primary' => true]
                )->addColumn(
                    'operation',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT
                );
            $this->resourceAdapter->createTable($triggerTable);
        } else {
            $this->deleteAllRecords($deltaLogName);
        }
        foreach (Trigger::getListOfEvents() as $event) {
            $triggerName = 'trg_' . $documentName . '_after_' . strtolower($event);
            $statement = $this->buildStatement($event, $idKey, $deltaLogName);
            $trigger = $this->triggerFactory->create()
                ->setTime(Trigger::TIME_AFTER)
                ->setEvent($event)
                ->setTable($documentName);
            $triggerKey = $documentName . $event . Trigger::TIME_AFTER;
            $triggerExists = $this->isTriggerExist($triggerKey);
            if ($triggerExists) {
                $triggerName = $this->triggers[$triggerKey]['trigger_name'];
                $oldTriggerStatement = $this->triggers[$triggerKey]['action_statement'];
                if (strpos($oldTriggerStatement, $statement) !== false) {
                    unset($trigger);
                    continue;
                }
                $trigger->addStatement($oldTriggerStatement);
                $this->resourceAdapter->dropTrigger($triggerName);
            }
            $trigger->addStatement($statement)->setName($triggerName);
            $this->resourceAdapter->createTrigger($trigger);
            if (!$triggerExists) {
                $this->triggers[$triggerKey] = 1;
            }
            unset($trigger);
        }
    }

    /**
     * @param string $event
     * @param string $idKey
     * @param string $triggerTableName
     * @return string
     */
    protected function buildStatement($event, $idKey, $triggerTableName)
    {
        $entityTime = ($event == Trigger::EVENT_DELETE) ? 'OLD' : 'NEW';
        return "INSERT INTO $triggerTableName VALUES ($entityTime.$idKey, '$event')"
            . "ON DUPLICATE KEY UPDATE operation = '$event'";
    }

    /**
     * @param string $triggerKey
     * @return bool
     */
    protected function isTriggerExist($triggerKey)
    {
        if (empty($this->triggers)) {
            $this->loadTriggers();
        }

        if (isset($this->triggers[$triggerKey])) {
            return true;
        }

        return false;
    }

    /**
     * Get all database triggers
     *
     * @return void
     */
    protected function loadTriggers()
    {
        $schema = $this->getSchemaName();
        if ($schema) {
            $sqlFilter = $this->resourceAdapter->quoteIdentifier('TRIGGER_SCHEMA')
                . ' = ' . $this->resourceAdapter->quote($schema);
        } else {
            $sqlFilter = $this->resourceAdapter->quoteIdentifier('TRIGGER_SCHEMA')
                . ' != ' . $this->resourceAdapter->quote('INFORMATION_SCHEMA');
        }
        $select = $this->getSelect()
            ->from(new \Zend_Db_Expr($this->resourceAdapter->quoteIdentifier(['INFORMATION_SCHEMA', 'TRIGGERS'])))
            ->where($sqlFilter);
        $results = $this->resourceAdapter->query($select);
        $data = [];
        foreach ($results as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $row['action_statement'] = $this->convertStatement($row['action_statement']);
            $key = $row['event_object_table'] . $row['event_manipulation'] . $row['action_timing'];
            $data[$key] = $row;
        }
        $this->triggers = $data;
    }

    /**
     * @param string $row
     * @return mixed
     */
    protected function convertStatement($row)
    {
        $regex = '/(BEGIN)([\s\S]*?)(END.?)/';
        return preg_replace($regex, '$2', $row);
    }

    /**
     * Returns current schema name
     *
     * @return string
     */
    protected function getCurrentSchema()
    {
        return $this->resourceAdapter->fetchOne('SELECT SCHEMA()');
    }

    /**
     * Returns schema name
     *
     * @return string
     */
    protected function getSchemaName()
    {
        if (!$this->schemaName) {
            $this->schemaName = $this->getCurrentSchema();
        }

        return $this->schemaName;
    }
}