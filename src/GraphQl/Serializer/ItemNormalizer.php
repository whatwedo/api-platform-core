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

namespace ApiPlatform\Core\GraphQl\Serializer;

use ApiPlatform\Core\Metadata\Property\PropertyMetadata;
use ApiPlatform\Core\Serializer\ItemNormalizer as BaseItemNormalizer;
use ApiPlatform\Core\Util\ClassInfoTrait;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * GraphQL normalizer.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ItemNormalizer extends BaseItemNormalizer
{
    use ClassInfoTrait;

    public const FORMAT = 'graphql';
    public const ITEM_KEY = '#item';

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null, array $context = [])
    {
        return self::FORMAT === $format && parent::supportsNormalization($data, $format, $context);
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnexpectedValueException
     */
    public function normalize($object, $format = null, array $context = [])
    {
        if (null !== $outputClass = $this->getOutputClass($this->getObjectClass($object), $context)) {
            return parent::normalize($object, $format, $context);
        }

        $data = parent::normalize($object, $format, $context);
        if (!\is_array($data)) {
            throw new UnexpectedValueException('Expected data to be an array');
        }

	    $data[self::ITEM_KEY] = serialize($this->cloneToEmptyObject($object)); // calling serialize prevent weird normalization process done by Webonyx's GraphQL PHP

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    protected function normalizeCollectionOfRelations(PropertyMetadata $propertyMetadata, $attributeValue, string $resourceClass, ?string $format, array $context): array
    {
        // to-many are handled directly by the GraphQL resolver
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization($data, $type, $format = null, array $context = [])
    {
        return self::FORMAT === $format && parent::supportsDenormalization($data, $type, $format, $context);
    }

    /**
     * {@inheritdoc}
     */
    protected function getAllowedAttributes($classOrObject, array $context, $attributesAsString = false)
    {
        $allowedAttributes = parent::getAllowedAttributes($classOrObject, $context, $attributesAsString);

        if (($context['api_denormalize'] ?? false) && \is_array($allowedAttributes) && false !== ($indexId = array_search('id', $allowedAttributes, true))) {
            $allowedAttributes[] = '_id';
            array_splice($allowedAttributes, (int) $indexId, 1);
        }

        return $allowedAttributes;
    }

    /**
     * {@inheritdoc}
     */
    protected function setAttributeValue($object, $attribute, $value, $format = null, array $context = [])
    {
        if ('_id' === $attribute) {
            $attribute = 'id';
        }

        parent::setAttributeValue($object, $attribute, $value, $format, $context);
    }

	/**
	 * Return object of passed type with all empty fields except id.
	 * Necessary to speed up serialization/deserialization on Webonyx side.
	 *
	 * @param object $originalObject
	 *
	 * @return object
	 */
	private function cloneToEmptyObject($originalObject)
	{
		$class = \get_class($originalObject);
		$emptyObject = new $class;

		try {
			$reflectionClass = new \ReflectionClass($class);
			$idProperty = $reflectionClass->getProperty('id');

			// For audit entities
			if ($reflectionClass->hasProperty('revision')) {
				$revProperty = $reflectionClass->getProperty('revision');
			}
		} catch (\ReflectionException $e) {
			return $originalObject;
		}

		$idProperty->setAccessible(true);
		$idProperty->setValue($emptyObject, $idProperty->getValue($originalObject));

		if (isset($revProperty)) {
			$revProperty->setAccessible(true);
			$revProperty->setValue($emptyObject, $revProperty->getValue($originalObject));
		}

		return $emptyObject;
	}
}
