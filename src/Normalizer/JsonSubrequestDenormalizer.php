<?php


namespace Drupal\subrequests\Normalizer;

use Drupal\Component\Utility\NestedArray;
use Drupal\subrequests\Blueprint\Parser;
use Drupal\subrequests\Blueprint\RequestTree;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class JsonSubrequestDenormalizer implements DenormalizerInterface {
  /**
   * Denormalizes data back into an object of the given class.
   *
   * @param mixed $data data to restore
   * @param string $class the expected class to instantiate
   * @param string $format format the given data was extracted from
   * @param array $context options available to the denormalizer
   *
   * @return object
   */
  public function denormalize($data, $class, $format = NULL, array $context = array()) {
    if (!Parser::isValidSubrequest($data)) {
      throw new \RuntimeException('The provided blueprint contains an invalid subrequest.');
    }
    $data['path'] = parse_url($data['path'], PHP_URL_PATH);
    if (isset($data['query']) && !is_array($data['query'])) {
      $query = array();
      parse_str($data['query'], $query);
      $data['query'] = $query;
    }
    $data = NestedArray::mergeDeep($data, array(
      'query' => array(),
      'body' => array(),
      'headers' => array(),
    ), parse_url($data['path']));

    /** @var \Symfony\Component\HttpFoundation\Request $master_request */
    $master_request = $context['master_request'];

    $request = Request::create(
      $data['path'],
      static::getMethodFromAction($data['action']),
      empty($data['body']) ? $data['query'] : $data['body'],
      $master_request->cookies ? (array) $master_request->cookies->getIterator() : [],
      $master_request->files ? (array) $master_request->files->getIterator() : [],
      $master_request->server ? (array) $master_request->server->getIterator() : [],
      empty($data['body']) ? '' : $data['body']
    );
    // Maintain the same session as in the master request.
    $request->setSession($master_request->getSession());
    // Replace the headers by the ones in the subrequest.
    $request->headers = new HeaderBag($data['headers']);

    // Add the content ID to the sub-request.
    $content_id = empty($data['requestId'])
      ? md5(serialize($data))
      : $data['requestId'];
    $request->headers->set('Content-ID', '<' . $content_id . '>');
    $request->attributes->set(RequestTree::SUBREQUEST_ID, $content_id);
    // If there is a parent, then add the ID to construct the tree.
    if (!empty($data['waitFor'])) {
      $request->attributes
        ->set(RequestTree::SUBREQUEST_PARENT_ID, $data['waitFor']);
    }

    return $request;
  }

  /**
   * Checks whether the given class is supported for denormalization by this
   * normalizer.
   *
   * @param mixed $data Data to denormalize from
   * @param string $type The class to which the data should be denormalized
   * @param string $format The format being deserialized from
   *
   * @return bool
   */
  public function supportsDenormalization($data, $type, $format = NULL) {
    return $format === 'json'
      && $type === Request::class
      && is_array($data)
      && JsonBlueprintDenormalizer::arrayIsKeyed($data);
  }

  /**
   * Gets the HTTP method from the list of allowed actions.
   *
   * @param string $action
   *   The action name.
   *
   * @return string
   *   The HTTP method.
   */
  public static function getMethodFromAction($action) {
    switch ($action) {
      case 'create':
        return Request::METHOD_POST;
      case 'update':
        return Request::METHOD_PATCH;
      case 'replace':
        return Request::METHOD_PUT;
      case 'delete':
        return Request::METHOD_DELETE;
      case 'exists':
        return Request::METHOD_HEAD;
      case 'discover':
        return Request::METHOD_OPTIONS;
      default:
        return Request::METHOD_GET;
    }
  }

}
