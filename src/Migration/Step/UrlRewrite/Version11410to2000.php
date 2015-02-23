<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\UrlRewrite;

use Migration\Step\DatabaseStep;

class Version11410to2000 extends DatabaseStep implements \Migration\Step\StepInterface
{
    /**
     * Resource of source
     *
     * @var \Migration\Resource\Source
     */
    protected $source;

    /**
     * Resource of destination
     *
     * @var \Migration\Resource\Destination
     */
    protected $destination;

    /**
     * Record Factory
     *
     * @var \Migration\Resource\RecordFactory
     */
    protected $recordFactory;

    /**
     * Record Collection Factory
     *
     * @var \Migration\Resource\Record\CollectionFactory
     */
    protected $recordCollectionFactory;

    /**
     * @param \Migration\Step\Progress $progress
     * @param \Migration\Logger\Logger $logger
     * @param \Migration\Config $config
     * @param \Migration\Resource\Source $source
     * @param \Migration\Resource\Destination $destination
     * @param \Migration\Resource\Record\CollectionFactory $recordCollectionFactory
     * @param \Migration\Resource\RecordFactory $recordFactory
     */
    public function __construct(
        \Migration\Step\Progress $progress,
        \Migration\Logger\Logger $logger,
        \Migration\Config $config,
        \Migration\Resource\Source $source,
        \Migration\Resource\Destination $destination,
        \Migration\Resource\Record\CollectionFactory $recordCollectionFactory,
        \Migration\Resource\RecordFactory $recordFactory
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->recordCollectionFactory = $recordCollectionFactory;
        $this->recordFactory = $recordFactory;
        parent::__construct($progress, $logger, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $sourceDocument = $this->source->getDocument('enterprise_url_rewrite');

        /** @var \Migration\Resource\Adapter\Mysql $adapter */
        $adapter = $this->source->getAdapter();
        $select = $adapter->getSelect();
        $select->from(['r' => $this->source->addDocumentPrefix('enterprise_url_rewrite')])
            ->joinLeft(
                ['c' => $this->source->addDocumentPrefix('catalog_category_entity_url_key')],
                'r.entity_type = 2 and r.value_id = c.value_id',
                ['category_id' => 'entity_id']
            )
            ->joinLeft(
                ['p' => $this->source->addDocumentPrefix('catalog_product_entity_url_key')],
                'r.entity_type = 3 and r.value_id = p.value_id',
                ['product_id' => 'entity_id']
            )
            ->joinLeft(
                ['t' => $this->source->addDocumentPrefix('enterprise_url_rewrite_redirect')],
                'r.entity_type = 1 and r.value_id = t.redirect_id',
                ['r_category_id' => 'category_id', 'r_product_id' => 'product_id', 'options']
            );

        $destinationDocument = $this->destination->getDocument('url_rewrite');
        $pageCount = $this->source->getRecordsCount($sourceDocument->getName()) / $this->source->getPageSize();
        for ($i = 0; $i < $pageCount; $i++) {
            $records = $this->recordCollectionFactory->create();
            $select->limit($this->source->getPageSize(), $this->source->getPageSize() * $i);
            $data = $adapter->loadDataFromSelect($select);
            foreach ($data as $row) {
                $records->addRecord($this->recordFactory->create(['data' => $row]));
            }
            $destinationRecords = $destinationDocument->getRecords();
            $this->migrateRewrites($records, $destinationRecords);
            $this->destination->saveRecords($destinationDocument->getName(), $destinationRecords);
        }
        $this->copyEavData('catalog_category_entity_url_key', 'catalog_category_entity_varchar');
        $this->copyEavData('catalog_product_entity_url_key', 'catalog_product_entity_varchar');
    }

    /**
     * @param \Migration\Resource\Record\Collection $source
     * @param \Migration\Resource\Record\Collection $destination
     * @return void
     */
    protected function migrateRewrites($source, $destination)
    {
        /** @var \Migration\Resource\Record $sourceRecord */
        foreach ($source as $sourceRecord) {
            /** @var \Migration\Resource\Record $destinationRecord */
            $destinationRecord = $this->recordFactory->create();
            $destinationRecord->setStructure($destination->getStructure());

            $destinationRecord->setValue('store_id', $sourceRecord->getValue('store_id'));
            $destinationRecord->setValue('description', $sourceRecord->getValue('description'));
            $destinationRecord->setValue('request_path', $sourceRecord->getValue('request_path'));
            $destinationRecord->setValue('redirect_type', 0);
            $destinationRecord->setValue('is_autogenerated', $sourceRecord->getValue('is_system'));
            $destinationRecord->setValue('metadata', '');

            $targetPath = $sourceRecord->getValue('target_path');

            switch ($sourceRecord->getValue('entity_type')) {
                case 1:
                    if ($sourceRecord->getValue('r_product_id') !== null
                        && $sourceRecord->getValue('r_category_id') !== null
                    ) {
                        $productId = $sourceRecord->getValue('r_product_id');
                        $categoryId = $sourceRecord->getValue('r_category_id');
                        $destinationRecord->setValue('entity_type', 'product');
                        $destinationRecord->setValue('entity_id', $productId);
                        $targetPath = "catalog/product/view/id/$productId/category/$categoryId";
                        $length = strlen($categoryId);
                        $metadata = sprintf('a:1:{s:11:"category_id";s:%s:"%s";}', $length, $categoryId);
                        $destinationRecord->setValue('metadata', $metadata);
                    } elseif ($sourceRecord->getValue('r_product_id')) {
                        $destinationRecord->setValue('entity_type', 'product');
                        $destinationRecord->setValue('entity_id', $sourceRecord->getValue('r_product_id'));
                        $targetPath = 'catalog/product/view/id/' . $sourceRecord->getValue('r_product_id');
                    } elseif ($sourceRecord->getValue('r_category_id')) {
                        $destinationRecord->setValue('entity_type', 'category');
                        $destinationRecord->setValue('entity_id', $sourceRecord->getValue('r_category_id'));
                        $targetPath = 'catalog/category/view/id/' . $sourceRecord->getValue('r_category_id');
                    } else {
                        $destinationRecord->setValue('entity_type', 'custom');
                        $destinationRecord->setValue('entity_id', 0);
                    }
                    break;
                case 2:
                    $destinationRecord->setValue('entity_type', 'category');
                    $destinationRecord->setValue('entity_id', $sourceRecord->getValue('category_id'));
                    $targetPath = 'catalog/category/view/id/' . $sourceRecord->getValue('category_id');
                    break;
                case 3:
                    $destinationRecord->setValue('entity_type', 'product');
                    $destinationRecord->setValue('entity_id', $sourceRecord->getValue('product_id'));
                    $targetPath = 'catalog/product/view/id/' . $sourceRecord->getValue('product_id');
                    break;
            }
            $destinationRecord->setValue(
                'target_path',
                $targetPath
            );
            $destination->addRecord($destinationRecord);
        }
    }

    /**
     * @param string $sourceName
     * @param string $destinationName
     * @return void
     */
    protected function copyEavData($sourceName, $destinationName)
    {
        $destinationDocument = $this->destination->getDocument($destinationName);
        $pageNumber = 0;
        while (!empty($recordsData = $this->source->getRecords($sourceName, $pageNumber))) {
            $pageNumber++;
            $records = $destinationDocument->getRecords();
            foreach ($recordsData as $row) {
                unset($row['value_id']);
                unset($row['entity_type_id']);
                $records->addRecord($this->recordFactory->create(['data' => $row]));
            }
            $this->destination->saveRecords($destinationName, $records);
        }
    }

    /**
     * @param \Migration\Resource\Record $record
     * @param array $data
     * @return $this
     */
    protected function setRecordData($record, $data)
    {
        foreach ($data as $key => $value) {
            $record->setValue($key, $value);
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxSteps()
    {
        return 100;
    }

    /**
     * {@inheritdoc}
     */
    public function integrity()
    {
        $result = $this->source->getDocument('enterprise_url_rewrite') !== false;
        $result = $result && $this->source->getDocument('catalog_category_entity_url_key') !== false;
        $result = $result && $this->source->getDocument('catalog_product_entity_url_key') !== false;
        $result = $result && $this->source->getDocument('enterprise_url_rewrite_redirect') !== false;
        $destinationDocument = $this->destination->getDocument('url_rewrite');
        $result = $result && $this->destination->getRecordsCount($destinationDocument->getName()) == 0;
        $result = $result && $this->destination->getDocument('catalog_category_entity_varchar') !== false;
        $result = $result && $this->destination->getDocument('catalog_product_entity_varchar') !== false;
        return $result;
    }
}
