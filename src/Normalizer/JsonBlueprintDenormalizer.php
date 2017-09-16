<?php

namespace Drupal\subrequests\Normalizer;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\Php;
use Drupal\subrequests\Subrequest;
use Drupal\subrequests\SubrequestsTree;
use JsonSchema\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Denormalizer that builds the blueprint based on the incoming blueprint.
 */
class JsonBlueprintDenormalizer implements DenormalizerInterface, SerializerAwareInterface {

  /**
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The Subrequests logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }


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
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    assert(
      '$this->validateInput($data)',
      'The input blueprint failed validation (see the logs for details). Please report this in the issue queue on drupal.org'
    );
    $data = array_map([$this, 'fillDefaults'], $data);
    $subrequests = array_map(function ($item) {
      return new Subrequest($item);
    }, $data);
    return $this->buildExecutionSequence($subrequests);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDenormalization($data, $type, $format = NULL) {
    return $format === 'json'
      && $type === SubrequestsTree::class
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
  protected static function arrayIsKeyed(array $input) {
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

  /**
   * Fill the defaults.
   *
   * @param array $raw_item
   *   The object to turn into a Subrequest input.
   *
   * @return array
   *   The complete Subrequest.
   */
  protected function fillDefaults($raw_item) {
    if (empty($raw_item['requestId'])) {
      $uuid = new Php();
      $raw_item['requestId'] = $uuid->generate();
    }
    if (!empty($raw_item['body'])) {
      $raw_item['body'] = Json::decode($raw_item['body']);
    }
    $raw_item['headers'] = !empty($raw_item['headers']) ? $raw_item['headers'] : [];
    $raw_item['waitFor'] = !empty($raw_item['waitFor']) ? $raw_item['waitFor'] : ['<ROOT>'];
    $raw_item['_resolved'] = FALSE;

    return $raw_item;
  }

  /**
   * Validates a response against the JSON API specification.
   *
   * @param mixed $input
   *   The blueprint sent by the consumer.
   *
   * @return bool
   *   FALSE if the input failed validation, otherwise TRUE.
   */
  protected function validateInput($input) {
    if (!class_exists("\\JsonSchema\\Validator")) {
      return TRUE;
    }

    $validator = new Validator();
    $schema_path = dirname(dirname(__DIR__)) . '/schema.json';

    $validator->check($input, (object) ['$ref' => 'file://' . $schema_path]);

    if (!$validator->isValid()) {
      // Log any potential errors.
      $this->logger->debug('Response failed validation: @data', [
        '@data' => Json::encode($input),
      ]);
      $this->logger->debug('Validation errors: @errors', [
        '@errors' => Json::encode($validator->getErrors()),
      ]);
    }

    return $validator->isValid();
  }

  /**
   * Builds the execution sequence.
   *
   * Builds an array where each position contains the IDs of the requests to be
   * executed. All the IDs in the same position in the sequence can be executed
   * in parallel.
   *
   * @param \Drupal\subrequests\Subrequest[] $parsed
   *   The parsed requests.
   *
   * @return SubrequestsTree
   *   The sequence of IDs grouped by execution order.
   */
  public function buildExecutionSequence(array $parsed) {
    $sequence = new SubrequestsTree();
    $rooted_reqs = array_filter($parsed, function (Subrequest $item) {
      return $item->waitFor === ['<ROOT>'];
    });
    $sequence->stack($rooted_reqs);
    $subreqs_with_unresolved_deps = array_values(
      array_filter($parsed, function (Subrequest $item) {
        return $item->waitFor !== ['<ROOT>'];
      })
    );
    $dependency_is_resolved = function (Subrequest $item) use ($sequence) {
      return empty(array_diff($item->waitFor, $sequence->allIds()));
    };
    while (count($subreqs_with_unresolved_deps)) {
      $no_deps = array_filter($subreqs_with_unresolved_deps, $dependency_is_resolved);
      $sequence->stack($no_deps);
      $diff = array_diff($subreqs_with_unresolved_deps, $no_deps);
      if (empty($no_deps)) {
        throw new BadRequestHttpException('Waiting for unresolvable request. Abort.');
      }
      $subreqs_with_unresolved_deps = $diff;
    }
    return $sequence;
  }

}
