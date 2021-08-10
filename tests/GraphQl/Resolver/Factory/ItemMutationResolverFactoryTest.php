<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Tests\GraphQl\Resolver\Factory;

use ApiPlatform\Core\Tests\ProphecyTrait;
use ApiPlatform\GraphQl\Resolver\Factory\ItemMutationResolverFactory;
use ApiPlatform\GraphQl\Resolver\Stage\DeserializeStageInterface;
use ApiPlatform\GraphQl\Resolver\Stage\ReadStageInterface;
use ApiPlatform\GraphQl\Resolver\Stage\SecurityPostDenormalizeStageInterface;
use ApiPlatform\GraphQl\Resolver\Stage\SecurityStageInterface;
use ApiPlatform\GraphQl\Resolver\Stage\SerializeStageInterface;
use ApiPlatform\GraphQl\Resolver\Stage\ValidateStageInterface;
use ApiPlatform\GraphQl\Resolver\Stage\WriteStageInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GraphQl\Mutation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\Dummy;
use GraphQL\Type\Definition\ResolveInfo;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Psr\Container\ContainerInterface;

/**
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
class ItemMutationResolverFactoryTest extends TestCase
{
    use ProphecyTrait;

    private $itemMutationResolverFactory;
    private $readStageProphecy;
    private $securityStageProphecy;
    private $securityPostDenormalizeStageProphecy;
    private $serializeStageProphecy;
    private $deserializeStageProphecy;
    private $writeStageProphecy;
    private $validateStageProphecy;
    private $mutationResolverLocatorProphecy;
    private $resourceMetadataCollectionFactoryProphecy;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->readStageProphecy = $this->prophesize(ReadStageInterface::class);
        $this->securityStageProphecy = $this->prophesize(SecurityStageInterface::class);
        $this->securityPostDenormalizeStageProphecy = $this->prophesize(SecurityPostDenormalizeStageInterface::class);
        $this->serializeStageProphecy = $this->prophesize(SerializeStageInterface::class);
        $this->deserializeStageProphecy = $this->prophesize(DeserializeStageInterface::class);
        $this->writeStageProphecy = $this->prophesize(WriteStageInterface::class);
        $this->validateStageProphecy = $this->prophesize(ValidateStageInterface::class);
        $this->mutationResolverLocatorProphecy = $this->prophesize(ContainerInterface::class);
        $this->resourceMetadataCollectionFactoryProphecy = $this->prophesize(ResourceMetadataCollectionFactoryInterface::class);

        $this->itemMutationResolverFactory = new ItemMutationResolverFactory(
            $this->readStageProphecy->reveal(),
            $this->securityStageProphecy->reveal(),
            $this->securityPostDenormalizeStageProphecy->reveal(),
            $this->serializeStageProphecy->reveal(),
            $this->deserializeStageProphecy->reveal(),
            $this->writeStageProphecy->reveal(),
            $this->validateStageProphecy->reveal(),
            $this->mutationResolverLocatorProphecy->reveal(),
            $this->resourceMetadataCollectionFactoryProphecy->reveal()
        );
    }

    public function testResolve(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = 'create';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();
        $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true, 'is_subscription' => false];

        $readStageItem = new \stdClass();
        $readStageItem->field = 'read';
        $this->readStageProphecy->__invoke($resourceClass, $rootClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($readStageItem);

        $deserializeStageItem = new \stdClass();
        $deserializeStageItem->field = 'deserialize';
        $this->deserializeStageProphecy->__invoke($readStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($deserializeStageItem);

        $this->resourceMetadataCollectionFactoryProphecy->create($resourceClass)->willReturn(new ResourceMetadataCollection($resourceClass, [(new ApiResource())->withGraphQlOperations([$operationName => new Mutation()])]));

        $this->securityStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $readStageItem,
            ],
        ])->shouldBeCalled();
        $this->securityPostDenormalizeStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $deserializeStageItem,
                'previous_object' => $readStageItem,
            ],
        ])->shouldBeCalled();

        $this->validateStageProphecy->__invoke($deserializeStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled();

        $writeStageItem = new \stdClass();
        $writeStageItem->field = 'write';
        $this->writeStageProphecy->__invoke($deserializeStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($writeStageItem);

        $serializeStageData = ['serialized'];
        $this->serializeStageProphecy->__invoke($writeStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($serializeStageData);

        $this->assertSame($serializeStageData, ($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info));
    }

    public function testResolveNullResourceClass(): void
    {
        $resourceClass = null;
        $rootClass = 'rootClass';
        $operationName = 'create';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();

        $this->assertNull(($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info));
    }

    public function testResolveNullOperationName(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = null;
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();

        $this->assertNull(($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info));
    }

    public function testResolveBadReadStageItem(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = 'create';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();
        $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true, 'is_subscription' => false];

        $readStageItem = [];
        $this->readStageProphecy->__invoke($resourceClass, $rootClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($readStageItem);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Item from read stage should be a nullable object.');

        ($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info);
    }

    public function testResolveNullDeserializeStageItem(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = 'create';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();
        $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true, 'is_subscription' => false];

        $readStageItem = new \stdClass();
        $readStageItem->field = 'read';
        $this->readStageProphecy->__invoke($resourceClass, $rootClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($readStageItem);

        $deserializeStageItem = null;
        $this->deserializeStageProphecy->__invoke($readStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($deserializeStageItem);

        $this->resourceMetadataCollectionFactoryProphecy->create($resourceClass)->willReturn(new ResourceMetadataCollection($resourceClass, [(new ApiResource())->withGraphQlOperations([$operationName => new Mutation()])]));

        $this->securityStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $readStageItem,
            ],
        ])->shouldBeCalled();
        $this->securityPostDenormalizeStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $deserializeStageItem,
                'previous_object' => $readStageItem,
            ],
        ])->shouldBeCalled();

        $this->validateStageProphecy->__invoke(Argument::cetera())->shouldNotBeCalled();

        $this->writeStageProphecy->__invoke(Argument::cetera())->shouldNotBeCalled();

        $serializeStageData = null;
        $this->serializeStageProphecy->__invoke($deserializeStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($serializeStageData);

        $this->assertNull(($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info));
    }

    public function testResolveDelete(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = 'delete';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();
        $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true, 'is_subscription' => false];

        $readStageItem = new \stdClass();
        $readStageItem->field = 'read';
        $this->readStageProphecy->__invoke($resourceClass, $rootClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($readStageItem);

        $this->deserializeStageProphecy->__invoke(Argument::cetera())->shouldNotBeCalled();

        $this->securityStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $readStageItem,
            ],
        ])->shouldBeCalled();
        $this->securityPostDenormalizeStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $readStageItem,
                'previous_object' => $readStageItem,
            ],
        ])->shouldBeCalled();

        $this->validateStageProphecy->__invoke(Argument::cetera())->shouldNotBeCalled();

        $writeStageItem = new \stdClass();
        $writeStageItem->field = 'write';
        $this->writeStageProphecy->__invoke($readStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($writeStageItem);

        $serializeStageData = ['serialized'];
        $this->serializeStageProphecy->__invoke($writeStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($serializeStageData);

        $this->assertSame($serializeStageData, ($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info));
    }

    public function testResolveCustom(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = 'create';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();
        $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true, 'is_subscription' => false];

        $readStageItem = new \stdClass();
        $readStageItem->field = 'read';
        $this->readStageProphecy->__invoke($resourceClass, $rootClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($readStageItem);

        $deserializeStageItem = new \stdClass();
        $deserializeStageItem->field = 'deserialize';
        $this->deserializeStageProphecy->__invoke($readStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($deserializeStageItem);

        $this->resourceMetadataCollectionFactoryProphecy->create($resourceClass)->willReturn(new ResourceMetadataCollection($resourceClass, [(new ApiResource())->withGraphQlOperations([$operationName => (new Mutation())->withResolver('query_resolver_id')])]));

        $customItem = new \stdClass();
        $customItem->field = 'foo';
        $this->mutationResolverLocatorProphecy->get('query_resolver_id')->shouldBeCalled()->willReturn(function () use ($customItem) {
            return $customItem;
        });

        $this->securityStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $readStageItem,
            ],
        ])->shouldBeCalled();
        $this->securityPostDenormalizeStageProphecy->__invoke($resourceClass, $operationName, $resolverContext + [
            'extra_variables' => [
                'object' => $customItem,
                'previous_object' => $readStageItem,
            ],
        ])->shouldBeCalled();

        $this->validateStageProphecy->__invoke($customItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled();

        $writeStageItem = new \stdClass();
        $writeStageItem->field = 'write';
        $this->writeStageProphecy->__invoke($customItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($writeStageItem);

        $serializeStageData = ['serialized'];
        $this->serializeStageProphecy->__invoke($writeStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($serializeStageData);

        $this->assertSame($serializeStageData, ($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info));
    }

    public function testResolveCustomBadItem(): void
    {
        $resourceClass = 'stdClass';
        $rootClass = 'rootClass';
        $operationName = 'create';
        $source = ['source'];
        $args = ['args'];
        $info = $this->prophesize(ResolveInfo::class)->reveal();
        $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true, 'is_subscription' => false];

        $readStageItem = new \stdClass();
        $readStageItem->field = 'read';
        $this->readStageProphecy->__invoke($resourceClass, $rootClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($readStageItem);

        $deserializeStageItem = new \stdClass();
        $deserializeStageItem->field = 'deserialize';
        $this->deserializeStageProphecy->__invoke($readStageItem, $resourceClass, $operationName, $resolverContext)->shouldBeCalled()->willReturn($deserializeStageItem);

        $this->resourceMetadataCollectionFactoryProphecy->create($resourceClass)->willReturn(new ResourceMetadataCollection($resourceClass, [(new ApiResource())->withGraphQlOperations([$operationName => (new Mutation())->withShortName('shortName')->withResolver('query_resolver_id')])]));

        $customItem = new Dummy();
        $this->mutationResolverLocatorProphecy->get('query_resolver_id')->shouldBeCalled()->willReturn(function () use ($customItem) {
            return $customItem;
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Custom mutation resolver "query_resolver_id" has to return an item of class shortName but returned an item of class Dummy.');

        ($this->itemMutationResolverFactory)($resourceClass, $rootClass, $operationName)($source, $args, null, $info);
    }
}
