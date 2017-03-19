<?php


namespace Drupal\subrequests\Blueprint;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * TODO: Change this comment. We'll use the serializer instead.
 * Base class for blueprint parsers. There may be slightly different blueprint
 * formats depending on the encoding. For instance, JSON encoded blueprints will
 * reference other properties in the responses using JSON pointers, while XML
 * encoded blueprints will use XPath.
 */
class Parser {

  /**
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * The Mime-Type of the incoming requests.
   *
   * @var string
   */
  protected $type;

  /**
   * Parser constructor.
   */
  public function __construct(SerializerInterface $serializer) {
    $this->serializer = $serializer;
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The master request to parse. We need from it:
   *     - Request body content.
   *     - Request mime-type.
   */
  public function parseRequest(Request $request) {
    $tree = $this->serializer->deserialize(
      $request->getContent(),
      RequestTree::class,
      $request->getRequestFormat()
    );
    $request->attributes->add(RequestTree::SUBREQUEST_TREE, $tree);
    // It assumed that all subrequests use the same Mime-Type.
    $this->type = $request->getMimeType($request->getRequestFormat());
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
      $this->type
    );
    $headers = ['Content-Type' => $content_type];

    $context = ['delimiter' => $delimiter];
    $content = $this->serializer->serialize($responses, 'multipart-related', $context);
    return Response::create($content, 207, $headers);
  }

  /**
   * Validates if a request can be constituted from this payload.
   *
   * @param array $data
   *   The user data representing a sub-request.
   *
   * @return bool
   *   TRUE if the data is valid. FALSE otherwise.
   */
  public static function isValidSubrequest(array $data) {
    // TODO: Implement this!
    return (bool) $data;
  }

}
