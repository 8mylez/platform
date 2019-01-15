<?php
declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Cache;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Version\Aggregate\VersionCommit\VersionCommitDefinition;
use Shopware\Core\Framework\Version\Aggregate\VersionCommitData\VersionCommitDataDefinition;
use Shopware\Core\Framework\Version\VersionDefinition;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;

class CachedEntitySearcher implements EntitySearcherInterface
{
    /**
     * @var EntityCacheKeyGenerator
     */
    private $cacheKeyGenerator;

    /**
     * @var TagAwareAdapterInterface
     */
    private $cache;

    /**
     * @var EntitySearcherInterface
     */
    private $decorated;

    public function __construct(
        EntityCacheKeyGenerator $cacheKeyGenerator,
        TagAwareAdapterInterface $cache,
        EntitySearcherInterface $decorated
    ) {
        $this->cacheKeyGenerator = $cacheKeyGenerator;
        $this->cache = $cache;
        $this->decorated = $decorated;
    }

    public function search(string $definition, Criteria $criteria, Context $context): IdSearchResult
    {
        if (in_array($definition, [VersionDefinition::class, VersionCommitDefinition::class, VersionCommitDataDefinition::class], true)) {
            return $this->decorated->search($definition, $criteria, $context);
        }

        $key = $this->cacheKeyGenerator->getSearchCacheKey($definition, $criteria, $context);

        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            return $item->get();
        }

        $result = $this->decorated->search($definition, $criteria, $context);

        $item->set($result);

        $tags = $this->cacheKeyGenerator->getSearchTags($definition, $criteria, $context);
        if (!$item instanceof CacheItem) {
            throw new \RuntimeException(sprintf('Cache adapter has to return instance of %s', CacheItem::class));
        }

        /* @var CacheItem $item */
        $item->tag($tags);
        $item->expiresAfter(3600);

        $this->cache->save($item);

        return $result;
    }
}
