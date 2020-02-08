<?php

/*
 * This file is part of the FOSElasticaBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\ElasticaBundle\Index;

use FOS\ElasticaBundle\Configuration\ManagerInterface;
use Elastica\Client;
use FOS\ElasticaBundle\Event\IndexResetEvent;
use FOS\ElasticaBundle\Event\TypeResetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as LegacyEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use FOS\ElasticaBundle\Event\PostIndexResetEvent;
use FOS\ElasticaBundle\Event\PreIndexResetEvent;

/**
 * Deletes and recreates indexes.
 */
class Resetter implements ResetterInterface
{
    /**
     * @var AliasProcessor
     */
    private $aliasProcessor;

    /***
     * @var ManagerInterface
     */
    private $configManager;

    /**
     * @var EventDispatcherInterface|LegacyEventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var IndexManager
     */
    private $indexManager;

    /**
     * @var MappingBuilder
     */
    private $mappingBuilder;

    /**
     * @param ManagerInterface                                        $configManager
     * @param IndexManager                                            $indexManager
     * @param AliasProcessor                                          $aliasProcessor
     * @param MappingBuilder                                          $mappingBuilder
     * @param EventDispatcherInterface|LegacyEventDispatcherInterface $eventDispatcher
     * @param Client                                                  $client
     */
    public function __construct(
        ManagerInterface $configManager,
        IndexManager $indexManager,
        AliasProcessor $aliasProcessor,
        MappingBuilder $mappingBuilder,
        /* EventDispatcherInterface */ $eventDispatcher
    ) {
        $this->aliasProcessor = $aliasProcessor;
        $this->configManager = $configManager;
        $this->dispatcher = $eventDispatcher;
        $this->indexManager = $indexManager;
        $this->mappingBuilder = $mappingBuilder;
    }

    /**
     * Deletes and recreates all indexes.
     */
    public function resetAllIndexes(bool $populating = false, bool $force = false)
    {
        foreach ($this->configManager->getIndexNames() as $name) {
            $this->resetIndex($name, $populating, $force);
        }
    }

    /**
     * Deletes and recreates the named index. If populating, creates a new index
     * with a randomised name for an alias to be set after population.
     *
     * @throws \InvalidArgumentException if no index exists for the given name
     */
    public function resetIndex(string $indexName, bool $populating = false, bool $force = false)
    {
        $indexConfig = $this->configManager->getIndexConfiguration($indexName);
        $index = $this->indexManager->getIndex($indexName);

        if ($indexConfig->isUseAlias()) {
            $this->aliasProcessor->setRootName($indexConfig, $index);
        }

        $this->dispatcher->dispatch($event = new PreIndexResetEvent($indexName, $populating, $force));

        $mapping = $this->mappingBuilder->buildIndexMapping($indexConfig);
        $index->create($mapping, true);

        if (!$populating and $indexConfig->isUseAlias()) {
            $this->aliasProcessor->switchIndexAlias($indexConfig, $index, $force);
        }

        $this->dispatcher->dispatch(new PostIndexResetEvent($indexName, $populating, $force));
    }

    /**
     * Switch index alias.
     *
     * @throws \FOS\ElasticaBundle\Exception\AliasIsIndexException
     */
    public function switchIndexAlias(string $indexName, bool $delete = true)
    {
        $indexConfig = $this->configManager->getIndexConfiguration($indexName);

        if ($indexConfig->isUseAlias()) {
            $index = $this->indexManager->getIndex($indexName);
            $this->aliasProcessor->switchIndexAlias($indexConfig, $index, false, $delete);
        }
    }

    private function dispatch($event, $eventName): void
    {
        if ($this->dispatcher instanceof EventDispatcherInterface) {
            // Symfony >= 4.3
            $this->dispatcher->dispatch($event, $eventName);
        } else {
            // Symfony 3.4
            $this->dispatcher->dispatch($eventName, $event);
        }
    }
}
