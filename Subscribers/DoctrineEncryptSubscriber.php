<?php

namespace Ambta\DoctrineEncryptBundle\Subscribers;

use ReflectionClass;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Util\ClassUtils;
use Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Doctrine event subscriber which encrypt/decrypt entities
 */
class DoctrineEncryptSubscriber implements EventSubscriber
{
    /**
     * Appended to end of encrypted value
     */
    const ENCRYPTION_MARKER = '<ENC>';
    
    /**
     * Encryptor interface namespace
     */
    const ENCRYPTOR_INTERFACE_NS = 'Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface';
    
    /**
     * Encrypted annotation full name
     */
    const ENCRYPTED_ANN_NAME = 'Ambta\DoctrineEncryptBundle\Configuration\Encrypted';
    
    /**
     * @var array
     */
    const CRYPTMAP_PROP = '__crypt_map';
    
    /**
     * Encryptor
     * @var EncryptorInterface
     */
    private $encryptor;
    
    /**
     * Annotation reader
     * @var \Doctrine\Common\Annotations\Reader
     */
    private $annReader;
    
    /**
     * Used for restoring the encryptor after changing it
     * @var string
     */
    private $restoreEncryptor;
    
    /**
     *
     * @var array
     */
    private array $encryptedPropsCache = [];
    
    /**
     *
     * @var array
     */
    private array $embeddedPropsCache  = [];
    
    /**
     * Count amount of decrypted values in this service
     * @var integer
     */
    public $decryptCounter = 0;
    
    /**
     * Count amount of encrypted values in this service
     * @var integer
     */
    public $encryptCounter = 0;
    
    /**
     * Initialization of subscriber
     *
     * @param Reader $annReader
     * @param string $encryptorClass  The encryptor class.  This can be empty if a service is being provided.
     * @param EncryptorInterface|NULL $service (Optional)  An EncryptorInterface.
     *
     * This allows for the use of dependency injection for the encrypters.
     */
    public function __construct(Reader $annReader, EncryptorInterface $encryptor)
    {
        $this->annReader = $annReader;
        $this->encryptor = $encryptor;
        $this->restoreEncryptor = $this->encryptor;
    }
    
    /**
     * Change the encryptor
     * @param [type] $[name] [<description>]
     * @param EncryptorInterface $encryptorClass
     */
    public function setEncryptor(EncryptorInterface $encryptorClass = null)
    {
        $this->encryptor = $encryptorClass;
    }
    
    /**
     * Get the current encryptor
     *
     * @return Object returns the encryptor class or null
     */
    public function getEncryptor()
    {
        return $this->encryptor;
    }
    
    /**
     * Restore encryptor set in config
     */
    public function restoreEncryptor()
    {
        $this->encryptor = $this->restoreEncryptor;
    }
    
    /**
     * Listen a postUpdate lifecycle event.
     * Decrypt entities property's values when post updated.
     *
     * So for example after form submit the preUpdate encrypted the entity
     * We have to decrypt them before showing them again.
     *
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        $this->processFields($entity, false);
        
        $em   = $args->getEntityManager();
        $uow  = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(\Doctrine\Common\Util\ClassUtils::getClass($entity));
        
        // Align originals to the decrypted values
        $this->syncOriginalEncryptedFields($meta, $uow, $entity);
        
        // Keep snapshots tidy for any postUpdate observers
        $uow->recomputeSingleEntityChangeSet($meta, $entity);
    }
    
    /**
     * Listen a preUpdate lifecycle event.
     * Encrypt entities property's values on preUpdate, so they will be stored encrypted
     *
     * @param PreUpdateEventArgs $args
     */
    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getEntity();
        
        // If none of the changed fields are @Encrypted, skip work
        $em   = $args->getEntityManager();
        $meta = $em->getClassMetadata(\Doctrine\Common\Util\ClassUtils::getClass($entity));
        $rc   = new \ReflectionClass($meta->getName());
        $encNames = array_flip($this->getEncryptedPropertyNames($rc));
        
        $hasEncryptedChange = false;
        foreach ($args->getEntityChangeSet() as $field => $_) {
            if (isset($encNames[$field])) { $hasEncryptedChange = true; break; }
        }
        if (!$hasEncryptedChange) {
            return;
        }
        
        // Otherwise, proceed and recompute
        $this->processFields($entity);
        $args->getEntityManager()->getUnitOfWork()
        ->recomputeSingleEntityChangeSet($meta, $entity);
    }
    
    
    /**
     * Listen a postLoad lifecycle event.
     * Decrypt entities property's values when loaded into the entity manger
     *
     * @param LifecycleEventArgs $args
     */
    public function postLoad(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        $this->processFields($entity, false);
        
        $em  = $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        $this->syncOriginalEncryptedFields($em->getClassMetadata(ClassUtils::getClass($entity)), $uow, $entity);
    }
    
    /**
     * After decrypting on postLoad, align UoW originals with current (decrypted) values
     */
    private function syncOriginalEncryptedFields(
        \Doctrine\ORM\Mapping\ClassMetadata $meta,
        \Doctrine\ORM\UnitOfWork $uow,
        $entity
        ): void {
            $oid = spl_object_hash($entity);
            $rc  = new \ReflectionClass($meta->getName());
            $pac = PropertyAccess::createPropertyAccessor();
            
            // 1) Root-level encrypted props
            foreach ($this->getEncryptedPropertyNames($rc) as $name) {
                $value = $pac->getValue($entity, $name);
                $uow->setOriginalEntityProperty($oid, $name, $value);
            }
            
            // 2) Embedded props (one level), set originals with dot notation
            foreach ($this->getEmbeddedPropertyNames($rc) as $embeddedName) {
                $embeddedEntity = $pac->getValue($entity, $embeddedName);
                if (!$embeddedEntity) {
                    continue;
                }
                
                $embeddedClass = \Doctrine\Common\Util\ClassUtils::getClass($embeddedEntity);
                $erc = new \ReflectionClass($embeddedClass);
                
                foreach ($this->getEncryptedPropertyNames($erc) as $encProp) {
                    $dotName = $embeddedName . '.' . $encProp;  // Doctrine field name for embeddables
                    $value   = $pac->getValue($embeddedEntity, $encProp);
                    $uow->setOriginalEntityProperty($oid, $dotName, $value);
                }
                
                // Optional nested embeddables (keep if you use them; else you can delete)
                foreach ($this->getEmbeddedPropertyNames($erc) as $nestedName) {
                    $nestedEntity = $pac->getValue($embeddedEntity, $nestedName);
                    if (!$nestedEntity) {
                        continue;
                    }
                    $nrc = new \ReflectionClass(\Doctrine\Common\Util\ClassUtils::getClass($nestedEntity));
                    foreach ($this->getEncryptedPropertyNames($nrc) as $nEncProp) {
                        $dotName = $embeddedName . '.' . $nestedName . '.' . $nEncProp;
                        $value   = $pac->getValue($nestedEntity, $nEncProp);
                        $uow->setOriginalEntityProperty($oid, $dotName, $value);
                    }
                }
            }
    }
    
    /**
     * Listen to preflush event
     * Encrypt entities that are inserted into the database
     *
     * @param PreFlushEventArgs $preFlushEventArgs
     */
    public function preFlush(PreFlushEventArgs $preFlushEventArgs)
    {
        $unitOfWork = $preFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->processFields($entity);
        }
    }
    
    /**
     * Listen to postFlush event
     * Decrypt entities that after inserted into the database
     *
     * @param PostFlushEventArgs $postFlushEventArgs
     */
    public function postFlush(PostFlushEventArgs $postFlushEventArgs)
    {
        $em  = $postFlushEventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();
        
        foreach ($uow->getIdentityMap() as $class => $entities) {
            $meta = $em->getClassMetadata($class);
            foreach ($entities as $entity) {
                $this->processFields($entity, false);
                
                // NEW: make decrypted values the new baseline
                $this->syncOriginalEncryptedFields($meta, $uow, $entity);
                // No need to recompute a changeset here; we want zero diffs.
            }
        }
    }
    
    /**
     * Realization of EventSubscriber interface method.
     *
     * @return array Return all events which this subscriber is listening
     */
    public function getSubscribedEvents()
    {
        return array(
            Events::postUpdate,
            Events::preUpdate,
            Events::postLoad,
            Events::preFlush,
            Events::postFlush,
        );
    }
    
    /**
     * Process (encrypt/decrypt) entities fields
     *
     * @param Object $entity doctrine entity
     * @param Boolean $isEncryptOperation If true - encrypt, false - decrypt entity
     *
     * @throws \RuntimeException
     *
     * @return object|null
     */
    public function processFields($entity, $isEncryptOperation = true)
    {
        if (empty($this->encryptor)) {
            return $entity;
        }
        
        $encryptorMethod = $isEncryptOperation ? 'encrypt' : 'decrypt';
        $realClass = \Doctrine\Common\Util\ClassUtils::getClass($entity);
        $rc = new \ReflectionClass($realClass);
        
        if (!isset($entity->{self::CRYPTMAP_PROP})) {
            $entity->{self::CRYPTMAP_PROP} = [];
        }
        
        $pac = PropertyAccess::createPropertyAccessor();
        
        // Handle embedded first (recurse)
        foreach ($this->getEmbeddedPropertyNames($rc) as $embeddedName) {
            $embeddedEntity = $pac->getValue($entity, $embeddedName);
            if ($embeddedEntity) {
                $this->processFields($embeddedEntity, $isEncryptOperation);
            }
        }
        
        // Then handle encrypted props
        foreach ($this->getEncryptedPropertyNames($rc) as $currentPropName) {
            $value = $pac->getValue($entity, $currentPropName);
            if (empty($value)) {
                continue;
            }
            
            if ($encryptorMethod === 'decrypt') {
                if (substr($value, -strlen(self::ENCRYPTION_MARKER)) === self::ENCRYPTION_MARKER) {
                    if (isset($entity->{self::CRYPTMAP_PROP}[$currentPropName.'#'.$value])) {
                        $pac->setValue($entity, $currentPropName, $entity->{self::CRYPTMAP_PROP}[$currentPropName.'#'.$value]);
                    } else {
                        $this->decryptCounter++;
                        $plain = $this->encryptor->decrypt(substr($value, 0, -strlen(self::ENCRYPTION_MARKER)));
                        $pac->setValue($entity, $currentPropName, $plain);
                        $entity->{self::CRYPTMAP_PROP}[$currentPropName.'#'.$plain] = $value;
                    }
                }
            } else { // encrypt
                if (substr($value, -strlen(self::ENCRYPTION_MARKER)) !== self::ENCRYPTION_MARKER) {
                    if (isset($entity->{self::CRYPTMAP_PROP}[$currentPropName.'#'.$value])) {
                        $pac->setValue($entity, $currentPropName, $entity->{self::CRYPTMAP_PROP}[$currentPropName.'#'.$value]);
                    } else {
                        $this->encryptCounter++;
                        $cipher = $this->encryptor->encrypt($value) . self::ENCRYPTION_MARKER;
                        $pac->setValue($entity, $currentPropName, $cipher);
                        $entity->{self::CRYPTMAP_PROP}[$currentPropName.'#'.$cipher] = $value;
                    }
                }
            }
        }
        
        return $entity;
    }
    
    /**
     *
     * @param \ReflectionClass $rc
     * @return array
     */
    private function getEncryptedPropertyNames(\ReflectionClass $rc): array
    {
        $class = $rc->getName();
        if (isset($this->encryptedPropsCache[$class])) {
            return $this->encryptedPropsCache[$class];
        }
        $props = [];
        foreach ($rc->getProperties() as $p) {
            if ($this->annReader->getPropertyAnnotation($p, self::ENCRYPTED_ANN_NAME)) {
                $props[] = $p->getName();
            }
        }
        if ($parent = $rc->getParentClass()) {
            $props = array_values(array_unique(array_merge($this->getEncryptedPropertyNames($parent), $props)));
        }
        return $this->encryptedPropsCache[$class] = $props;
    }
    
    /**
     *
     * @param \ReflectionClass $rc
     * @return array
     */
    private function getEmbeddedPropertyNames(\ReflectionClass $rc): array
    {
        $class = $rc->getName();
        if (isset($this->embeddedPropsCache[$class])) {
            return $this->embeddedPropsCache[$class];
        }
        $props = [];
        foreach ($rc->getProperties() as $p) {
            if ($this->annReader->getPropertyAnnotation($p, 'Doctrine\ORM\Mapping\Embedded')) {
                $props[] = $p->getName();
            }
        }
        if ($parent = $rc->getParentClass()) {
            $props = array_values(array_unique(array_merge($this->getEmbeddedPropertyNames($parent), $props)));
        }
        return $this->embeddedPropsCache[$class] = $props;
    }
    
}
