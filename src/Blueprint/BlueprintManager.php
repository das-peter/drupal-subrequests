<?php

namespace Drupal\subrequests\Blueprint;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\subrequests\SubrequestsTree;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Serializer;

class BlueprintManager {

  /**
   * The deserializer.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  public function __construct(Serializer $serializer) {
    $this->serializer = $serializer;
  }

  /**
   * Takes the user input and returns a subrequest tree ready for execution.
   *
   * @param string $input
   *   The input from the user.
   *
   * @return \Drupal\subrequests\SubrequestsTree
   */
  public function parse($input, Request $request) {
    /** @var \Drupal\subrequests\SubrequestsTree $output */
    $output = $this->serializer
      ->deserialize($input, SubrequestsTree::class, 'json');
    $output->setMasterRequest($request);
    return $output;
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Response[] $responses
   *   The responses to combine.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The combined response with a 207.
   */
  public function combineResponses(array $responses) {
    $delimiter = md5(microtime());

    // Prepare the root content type header.
    $content_type = sprintf(
      'multipart/related; boundary="%s", type=%s',
      $delimiter,
      $this->negotiateSubContentType($responses)
    );
    $headers = ['Content-Type' => $content_type];

    $context = ['delimiter' => $delimiter];
    // Set the content.
    $content = $this->serializer->normalize($responses, 'multipart-related', $context);
    $response = CacheableResponse::create($content, 207, $headers);
    // Set the cacheability metadata.
    $cacheable_responses = array_filter($responses, function ($response) {
      return $response instanceof CacheableResponseInterface;
    });
    array_walk($cacheable_responses, function (CacheableResponseInterface $partial_response) use ($response) {
      $response->addCacheableDependency($partial_response->getCacheableMetadata());
    });

    return $response;
  }

  /**
   * Negotiates the sub Content-Type.
   *
   * Checks if all responses have the same Content-Type header. If they do, then
   * it returns that one. If not, it defaults to 'application/json'.
   *
   * @param \Symfony\Component\HttpFoundation\Response[] $responses
   *   The responses.
   *
   * @return string
   *   The collective content type. 'application/json' if no conciliation is
   *   possible.
   */
  protected function negotiateSubContentType($responses) {
    $output = array_reduce($responses, function ($carry, Response $response) {
      $ct = $response->headers->get('Content-Type');
      if (!isset($carry)) {
        $carry = $ct;
      }
      if ($carry !== $ct) {
        $carry = 'application/json';
      }
      return $carry;
    });
    return $output ?: 'application/json';
  }

}
