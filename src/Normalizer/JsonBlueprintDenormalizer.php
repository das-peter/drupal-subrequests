<?php


namespace Drupal\subrequests\Normalizer;

use Drupal\subrequests\Blueprint\RequestTree;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Serializer;

class JsonBlueprintDenormalizer implements DenormalizerInterface, SerializerAwareInterface {

  /**
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * {@inheritdoc}
   */
  public function setSerializer(SerializerInterface $serializer) {
    if (!is_a($serializer, Serializer::class)) {
      throw new \ErrorException('Serializer is unable to normalize or denormalize.');
    }
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = array()) {
    // The top level is an array of normalized requests.
    $requests = array_map(function ($item) use ($format) {
      return $this->serializer->denormalize($item, Request::class, $format);
    }, $data);
    return new RequestTree($requests);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDenormalization($data, $type, $format = NULL) {
    return $format === 'json'
      && $type === RequestTree::class
      && is_array($data)
      && !static::arrayIsKeyed($data);
  }

  /**
   * Check if an array is keyed.
   *
   * @param array $input
   *   The input array to check.
   *
   * @return bool
   *   True if the array is keyed.
   */
  public static function arrayIsKeyed(array $input) {
    $keys = array_keys($input);
    // If the array does not start at 0, it is not numeric.
    if ($keys[0] !== 0) {
      return TRUE;
    }
    // If there is a non-numeric key, the array is not numeric.
    $numeric_keys = array_filter($keys, 'is_numeric');
    if (count($keys) != count($numeric_keys)) {
      return TRUE;
    }
    // If the keys are not following the natural numbers sequence, then it is
    // not numeric.
    for ($index = 1; $index < count($keys); $index++) {
      if ($keys[$index] - $keys[$index - 1] !== 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
