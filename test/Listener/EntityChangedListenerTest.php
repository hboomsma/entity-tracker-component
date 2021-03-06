<?php
namespace Hostnet\Component\EntityTracker\Listener;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Proxy\Proxy;
use Hostnet\Component\EntityTracker\Event\EntityChangedEvent;
use Hostnet\Component\EntityTracker\Events;
use Hostnet\Component\EntityTracker\Provider\EntityAnnotationMetadataProvider;
use Hostnet\Component\EntityTracker\Provider\EntityMutationMetadataProvider;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

/**
 * Listener for the Entities that use the Mutation Annotation.
 *
 * @author Yannick de Lange <ydelange@hostnet.nl>
 * @author Iltar van der Berg <ivanderberg@hostnet.nl>
 * @covers Hostnet\Component\EntityTracker\Listener\EntityChangedListener
 */
class EntityChangedListenerTest extends \PHPUnit_Framework_TestCase
{
    private $meta_annotation_provider;
    private $meta_mutation_provider;
    private $listener;
    private $event_manager;
    private $em;
    private $logger;
    private $event;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->meta_annotation_provider = $this->prophesize(EntityAnnotationMetadataProvider::class);
        $this->meta_mutation_provider   = $this->prophesize(EntityMutationMetadataProvider::class);
        $this->em                       = $this->prophesize(EntityManagerInterface::class);
        $this->event                    = $this->prophesize(PreFlushEventArgs::class);
        $this->event_manager            = $this->prophesize(EventManager::class);
        $this->logger                   = $this->prophesize(LoggerInterface::class);

        $this->em->getEventManager()->willReturn($this->event_manager->reveal());

        $this->listener = new EntityChangedListener(
            $this->meta_annotation_provider->reveal(),
            $this->meta_mutation_provider->reveal(),
            $this->logger->reveal()
        );
    }

    public function testPreFlushNoAnnotation()
    {
        $entity = new \stdClass();
        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity));
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldNotBeCalled();
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn(false);
        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushEmptyChanges()
    {
        $this->meta_mutation_provider->getFullChangeSet($this->em->reveal())->willReturn([[]]);
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldNotBeCalled();
        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushUnmanaged()
    {
        $entity = new \stdClass();
        $this->meta_mutation_provider->getFullChangeSet($this->em->reveal())->willReturn(
            $this->genericEntityDataProvider($entity)
        );
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldNotBeCalled();
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->isEntityManaged($this->em->reveal(), $entity)->willReturn(false);
        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushWithoutMutatedFields()
    {
        $entity   = new \stdClass();
        $original = new \stdClass();

        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity));
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldNotBeCalled();
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->isEntityManaged($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->createOriginalEntity($this->em->reveal(), $entity)->willReturn($original);
        $this->meta_mutation_provider->getMutatedFields($this->em->reveal(), $entity, $entity)->willReturn([]);

        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushWithMutatedFields()
    {
        $entity       = new \stdClass();
        $entity->id   = 1;
        $original     = new \stdClass();
        $original->id = 0;

        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity));
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn(true);
        $this->logger->info(Argument::cetera())->shouldBeCalled();
        $this->meta_mutation_provider->isEntityManaged($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->createOriginalEntity($this->em->reveal(), $entity)->willReturn($original);
        $this->meta_mutation_provider->getMutatedFields($this->em->reveal(), $entity, $original)->willReturn(['id']);
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldBeCalledTimes(1);

        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushWithNewEntity()
    {
        $entity = new \stdClass();
        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity));
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->isEntityManaged($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->createOriginalEntity($this->em->reveal(), $entity)->willReturn(null);

        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldNotBeCalled();

        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushWithProxy()
    {
        $entity = $this->prophesize(Proxy::class)->reveal();
        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity));
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldNotBeCalled();
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn(true);
        $this->meta_mutation_provider->isEntityManaged($this->em->reveal(), $entity)->willReturn(true);
        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    public function testPreFlushWithInitializedProxy()
    {
        $original     = new \stdClass();
        $original->id = 0;

        $entity = $this->prophesize(Proxy::class);
        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity->reveal()));
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity->reveal())->willReturn(true);
        $this->meta_mutation_provider->isEntityManaged($this->em->reveal(), $entity->reveal())->willReturn(true);
        $entity->__isInitialized()->willReturn(true);
        $this->logger->info(Argument::cetera())->shouldBeCalled();
        $this->meta_mutation_provider->createOriginalEntity($this->em->reveal(), $entity)->willReturn($original);
        $this->meta_mutation_provider
            ->getMutatedFields($this->em->reveal(), $entity->reveal(), $original)
            ->willReturn(['id']);

        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldBeCalledTimes(1);


        $this->listener->preFlush(new PreFlushEventArgs($this->em->reveal()));
    }

    /**
     * @param $tracked
     * @dataProvider prePersistProvider
     */
    public function testPrePersist($tracked)
    {
        $entity = new \stdClass();

        $this->meta_mutation_provider
            ->getFullChangeSet($this->em->reveal())
            ->willReturn($this->genericEntityDataProvider($entity));
        $this->meta_annotation_provider->isTracked($this->em->reveal(), $entity)->willReturn($tracked);
        $this->meta_mutation_provider
            ->getMutatedFields($this->em->reveal(), $entity, null)
            ->willReturn(['id']);
        $this->event_manager
            ->dispatchEvent(Events::ENTITY_CHANGED, Argument::type(EntityChangedEvent::class))
            ->shouldBeCalledTimes($tracked ? 1 : 0);

        $this->listener->prePersist(new LifecycleEventArgs($entity, $this->em->reveal()));
    }

    /**
     * @return array
     */
    public function prePersistProvider()
    {
        return [[true], [false]];
    }

    /**
     * @param  mixed $entity
     * @return array[]
     */
    private function genericEntityDataProvider($entity)
    {
        return [
            get_class($entity) => [$entity],
        ];
    }
}
