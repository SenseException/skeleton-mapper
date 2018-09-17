<?php

namespace Doctrine\SkeletonMapper;

use Doctrine\Common\EventManager;
use Doctrine\Common\NotifyPropertyChanged;
use Doctrine\Common\PropertyChangedListener;
use Doctrine\SkeletonMapper\Persister\ObjectPersisterFactoryInterface;
use Doctrine\SkeletonMapper\ObjectRepository\ObjectRepositoryFactoryInterface;
use Doctrine\SkeletonMapper\UnitOfWork\Change;
use Doctrine\SkeletonMapper\UnitOfWork\ChangeSets;
use Doctrine\SkeletonMapper\UnitOfWork\EventDispatcher;
use Doctrine\SkeletonMapper\UnitOfWork\Persister;

/**
 * Class for managing the persistence of objects.
 *
 * @author Jonathan H. Wage <jonwage@gmail.com>
 */
class UnitOfWork implements PropertyChangedListener
{
    /**
     * @var \Doctrine\SkeletonMapper\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Doctrine\SkeletonMapper\ObjectRepository\ObjectRepositoryFactoryInterface
     */
    private $objectRepositoryFactory;

    /**
     * @var \Doctrine\SkeletonMapper\Persister\ObjectPersisterFactoryInterface
     */
    private $objectPersisterFactory;

    /**
     * @var \Doctrine\SkeletonMapper\ObjectIdentityMap
     */
    private $objectIdentityMap;

    /**
     * @var \Doctrine\SkeletonMapper\UnitOfWork\EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var \Doctrine\SkeletonMapper\UnitOfWork\Persister
     */
    private $persister;

    /**
     * @var array
     */
    private $objectsToPersist = array();

    /**
     * @var array
     */
    private $objectsToUpdate = array();

    /**
     * @var array
     */
    private $objectsToRemove = array();

    /**
     * @var \Doctrine\SkeletonMapper\UnitOfWork\ChangeSets
     */
    private $objectChangeSets = array();

    /**
     * @param \Doctrine\SkeletonMapper\ObjectManagerInterface                            $objectManager
     * @param \Doctrine\SkeletonMapper\ObjectRepository\ObjectRepositoryFactoryInterface $objectRepositoryFactory
     * @param \Doctrine\SkeletonMapper\Persister\ObjectPersisterFactoryInterface         $objectPersisterFactory
     * @param \Doctrine\SkeletonMapper\ObjectIdentityMap                                 $objectIdentityMap
     * @param \Doctrine\Common\EventManager                                              $eventManager
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        ObjectRepositoryFactoryInterface $objectRepositoryFactory,
        ObjectPersisterFactoryInterface $objectPersisterFactory,
        ObjectIdentityMap $objectIdentityMap,
        EventManager $eventManager)
    {
        $this->objectManager = $objectManager;
        $this->objectRepositoryFactory = $objectRepositoryFactory;
        $this->objectPersisterFactory = $objectPersisterFactory;
        $this->objectIdentityMap = $objectIdentityMap;

        $this->eventDispatcher = new EventDispatcher(
            $objectManager, $eventManager
        );
        $this->persister = new Persister(
            $this->objectManager,
            $this,
            $this->eventDispatcher,
            $this->objectIdentityMap
        );

        $this->objectChangeSets = new ChangeSets();
    }

    /**
     * @param object $object
     */
    public function merge($object)
    {
        $this->getObjectRepository($object)->merge($object);
    }

    /**
     * @param object $object
     */
    public function persist($object)
    {
        if ($this->isScheduledForPersist($object)) {
            throw new \InvalidArgumentException('Object is already scheduled for persist.');
        }

        $this->eventDispatcher->dispatchPrePersist($object);

        $this->objectsToPersist[spl_object_hash($object)] = $object;

        if ($object instanceof NotifyPropertyChanged) {
            $object->addPropertyChangedListener($this);
        }
    }

    /**
     * @param object $object The instance to update
     */
    public function update($object)
    {
        if ($this->isScheduledForUpdate($object)) {
            throw new \InvalidArgumentException('Object is already scheduled for update.');
        }

        $this->eventDispatcher->dispatchPreUpdate(
            $object, $this->getObjectChangeSet($object)
        );

        $this->objectsToUpdate[spl_object_hash($object)] = $object;
    }

    /**
     * @param object $object The object instance to remove.
     */
    public function remove($object)
    {
        if ($this->isScheduledForRemove($object)) {
            throw new \InvalidArgumentException('Object is already scheduled for remove.');
        }

        $this->eventDispatcher->dispatchPreRemove($object);

        $this->objectsToRemove[spl_object_hash($object)] = $object;
    }

    /**
     * @param string|null $objectName
     */
    public function clear($objectName = null)
    {
        $this->objectIdentityMap->clear($objectName);

        $this->objectsToPersist = array();
        $this->objectsToUpdate = array();
        $this->objectsToRemove = array();
        $this->objectChangeSets = new ChangeSets();

        $this->eventDispatcher->dispatchOnClearEvent($objectName);
    }

    /**
     * @param object $object
     */
    public function detach($object)
    {
        $this->objectIdentityMap->detach($object);
    }

    /**
     * @param object $object
     */
    public function refresh($object)
    {
        $this->getObjectRepository($object)->refresh($object);
    }

    /**
     * @param object $object
     */
    public function contains($object)
    {
        return $this->objectIdentityMap->contains($object)
            || $this->isScheduledForPersist($object);
    }

    /**
     * Commit the contents of the unit of work.
     */
    public function commit()
    {
        $this->eventDispatcher->dispatchPreFlush();

        if (!($this->objectsToPersist ||
            $this->objectsToUpdate ||
            $this->objectsToRemove)
        ) {
            return; // Nothing to do.
        }

        $objects = array_merge(
            $this->objectsToPersist,
            $this->objectsToUpdate,
            $this->objectsToRemove
        );
        $this->eventDispatcher->dispatchPreFlushLifecycleCallbacks($objects);

        $this->eventDispatcher->dispatchOnFlush();

        $this->persister->executePersists();
        $this->persister->executeUpdates();
        $this->persister->executeRemoves();

        $this->eventDispatcher->dispatchPostFlush();

        $this->objectsToPersist = array();
        $this->objectsToUpdate = array();
        $this->objectsToRemove = array();
        $this->objectChangeSets = new ChangeSets();
    }

    /**
     * @param object $object
     *
     * @return bool
     */
    public function isScheduledForPersist($object)
    {
        return isset($this->objectsToPersist[spl_object_hash($object)]);
    }

    /**
     * @return array
     */
    public function getObjectsToPersist()
    {
        return $this->objectsToPersist;
    }

    /**
     * @param object $object
     *
     * @return bool
     */
    public function isScheduledForUpdate($object)
    {
        return isset($this->objectsToUpdate[spl_object_hash($object)]);
    }

    /**
     * @return array
     */
    public function getObjectsToUpdate()
    {
        return $this->objectsToUpdate;
    }

    /**
     * @param object $object
     *
     * @return bool
     */
    public function isScheduledForRemove($object)
    {
        return isset($this->objectsToRemove[spl_object_hash($object)]);
    }

    /**
     * @return array
     */
    public function getObjectsToRemove()
    {
        return $this->objectsToRemove;
    }

    /* PropertyChangedListener implementation */

    /**
     * Notifies this UnitOfWork of a property change in an object.
     *
     * @param object $object       The entity that owns the property.
     * @param string $propertyName The name of the property that changed.
     * @param mixed  $oldValue     The old value of the property.
     * @param mixed  $newValue     The new value of the property.
     */
    public function propertyChanged($object, $propertyName, $oldValue, $newValue)
    {
        if (!$this->isInIdentityMap($object)) {
            return;
        }

        if (!$this->isScheduledForUpdate($object)) {
            $this->update($object);
        }

        $this->objectChangeSets->addObjectChange(
            $object,
            new Change($propertyName, $oldValue, $newValue)
        );
    }

    /**
     * Gets the changeset for a object.
     *
     * @param object $object
     *
     * @return \Doctrine\SkeletonMapper\UnitOfWork\ChangeSet
     */
    public function getObjectChangeSet($object)
    {
        return $this->objectChangeSets->getObjectChangeSet($object);
    }

    /**
     * Checks whether an object is registered in the identity map of this UnitOfWork.
     *
     * @param object $object
     *
     * @return bool
     */
    public function isInIdentityMap($object)
    {
        return $this->objectIdentityMap->contains($object);
    }

    /**
     * @param string $className
     * @param array  $data
     *
     * @return object
     */
    public function getOrCreateObject($className, array $data)
    {
        if ($object = $this->objectIdentityMap->tryGetById($className, $data)) {
            return $object;
        }

        return $this->createObject($className, $data);
    }

    /**
     * @param object $object
     *
     * @return \Doctrine\SkeletonMapper\Persister\ObjectPersisterInterface
     */
    public function getObjectPersister($object)
    {
        return $this->objectPersisterFactory
            ->getPersister(get_class($object));
    }

    /**
     * @param object $object
     *
     * @return \Doctrine\Common\Persistence\ObjectRepository
     */
    public function getObjectRepository($object)
    {
        return $this->objectManager
            ->getRepository(get_class($object));
    }

    /**
     * @param string $className
     * @param array  $data
     *
     * @return object
     */
    private function createObject($className, array $data)
    {
        $repository = $this->objectManager->getRepository($className);

        $object = $repository->create($className);

        if ($object instanceof NotifyPropertyChanged) {
            $object->addPropertyChangedListener($this);
        }

        $this->eventDispatcher->dispatchPreLoad($object, $data);

        $repository->hydrate($object, $data);

        $this->eventDispatcher->dispatchPostLoad($object);

        $this->objectIdentityMap->addToIdentityMap($object, $data);

        return $object;
    }
}
