<?php

namespace Drupal\subrequests;

use JsonPath\JsonObject;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class JsonPathReplacer {

  /**
   * Performs the JSON Path replacements in the whole batch.
   *
   * @param \Drupal\subrequests\Subrequest[] $batch
   *   The subrequests that contain replacement tokens.
   * @param \Symfony\Component\HttpFoundation\Response[] $responses
   *   The accumulated responses from previous requests.
   *
   * @return \Drupal\subrequests\Subrequest[]
   *   An array of subrequests. Note that one input subrequest can generate N
   *   output subrequests. This is because JSON path expressinos can return
   *   multiple values.
   */
  public function replaceBatch(array $batch, array $responses) {
    return array_reduce($batch, function (array $carry, Subrequest $subrequest) use ($responses) {
      return array_merge(
        $carry,
        $this->replaceItem($subrequest, $responses)
      );
    }, []);
  }

  /**
   * Searches for JSONPath tokens in the request and replaces it with the values
   * from previous responses.
   *
   * @param \Drupal\subrequests\Subrequest $subrequest
   *   The list of requests that can contain tokens.
   * @param \Symfony\Component\HttpFoundation\Response[] $pool
   *   The pool of responses that can content the values to replace.
   *
   * @returns \Drupal\subrequests\Subrequest[]
   *   The new list of requests. Note that if a JSONPath token yields many
   *   values then several replaced subrequests will be generated from the input
   *   subrequest.
   */
  protected function replaceItem(Subrequest $subrequest, array $pool) {
    $token_replacements = [
      'uri' => $this->extractTokenReplacements($subrequest, 'uri', $pool),
      'body' => $this->extractTokenReplacements($subrequest, 'body', $pool),
    ];
    if (count($token_replacements['uri']) !== 0) {
      return $this->replaceBatch(
        $this->doReplaceTokensInLocation($token_replacements, $subrequest, 'uri'),
        $pool
      );
    }
    if (count($token_replacements['body']) !== 0) {
      return $this->replaceBatch(
        $this->doReplaceTokensInLocation($token_replacements, $subrequest, 'body'),
        $pool
      );
    }
    // If there are no replacements necessary, then just return the initial
    // request.
    $subrequest->_resolved = TRUE;
    return [$subrequest];
  }

  /**
   * Creates replacements for either the body or the URI.
   *
   * @param array $token_replacements
   *   Holds the info to replace text.
   * @param \Drupal\subrequests\Subrequest $tokenized_subrequest
   *   The original copy of the subrequest.
   * @param string $token_location
   *   Either 'body' or 'uri'.
   *
   * @returns \Drupal\subrequests\Subrequest[]
   *   The replaced subrequests.
   *
   * @private
   */
  protected function doReplaceTokensInLocation(array $token_replacements, $tokenized_subrequest, $token_location) {
    $replacements = [];
    // For each subrequest, we need to replace all the tokens.
    $tr = [];
    // Go from the flat array with the $id_tuple to an array of arrays.
    foreach ($token_replacements[$token_location] as $id_tuple => $key_val) {
      list($req_id, $idx) = explode('::', $id_tuple);
      $tr[$req_id] = empty($tr[$req_id]) ? [] : $tr[$req_id];
      $tr[$req_id][$idx] = $key_val;
    }
    $points = $this->getPoints($tr);
    $keys = array_keys($tr);
    foreach ($points as $index => $point) {
      // Clone the subrequest.
      $cloned = clone $tokenized_subrequest;
      $cloned->requestId = sprintf(
        '%s#%s{%s}',
        $tokenized_subrequest->requestId,
        $token_location,
        $index
      );
      // Now replace all the tokens in the request member (body or URI).
      $token_subject = $this->serializeMember($token_location, $cloned->{$token_location});
      foreach ($point as $axis => $position) {
        $replacement_info = $tr[$keys[$axis]][$position];
        $value = reset($replacement_info);
        $token = key($replacement_info);
        $regexp = sprintf('/%s/', preg_quote($token), '/');
        $token_subject = preg_replace($regexp, $value, $token_subject);
      }
      $cloned->{$token_location} = $this->deserializeMember($token_location, $token_subject);
      array_push($replacements, $cloned);
    }
    return $replacements;
  }

  /**
   * Generates a list of sets of coordinates for the token replacements.
   *
   * Each point (coordinates set) end up creating a new clone of the tokenized
   * subrequest.
   *
   * @param array $tr
   *   Token replacements array structure.
   *
   * @return array
   *   The coordinates sets.
   */
  protected function getPoints($tr) {
    $indices_matrix = array_reduce($tr, function ($carry, array $found_replacements) {
      $carry[] = array_keys($found_replacements);
      return $carry;
    }, []);
    $points = [];
    foreach ($indices_matrix as $current) {
      $new_points = [];
      foreach ($current as $index) {
        if (empty($points)) {
          $new_points[] = [$index];
        }
        else {
          foreach ($points as $coordinate_set) {
            $new_points[] = array_merge($coordinate_set, [$index]);
          }
        }
      }
      $points = $new_points;
    }
    return $points;
  }

  /**
   * Makes sure that the subject for replacement is a string.
   *
   * This is an abstraction to be able to treat 'uri' and 'body' replacements
   * the same way.
   *
   * @param string $member_name
   *   Either 'body' or 'uri'.
   * @param mixed $value
   *   The contents of the URI or the subrequest body.
   *
   * @returns string
   *   The serialized member.
   */
  protected function serializeMember($member_name, $value) {
    return $member_name === 'body'
      // The body is an Object, to replace on it we serialize it first.
      ? Json::encode($value)
      : $value;
  }

  /**
   * Undoes the serialization that happened in _serializeMember.
   *
   * This is an abstraction to be able to treat 'uri' and 'body' replacements
   * the same way.
   *
   * @param string $member_name
   *   Either 'body' or 'uri'.
   * @param string $serialized
   *   The contents of the serialized URI or the serialized subrequest body.
   *
   * @returns mixed
   *   The unserialized member.
   */
  protected function deserializeMember($member_name, $serialized) {
    return $member_name === 'body'
      // The body is an Object, to replace on it we serialize it first.
      ? Json::decode($serialized)
      : $serialized;
  }

  /**
   * Extracts the token replacements for a given subrequest.
   *
   * Given a subrequest there can be N tokens to be replaced. Each token can
   * result in an list of values to be replaced. Each token may refer to many
   * subjects, if the subrequest referenced in the token ended up spawning
   * multiple responses. This function detects the tokens and finds the
   * replacements for each token. Then returns a data structure that contains a
   * list of replacements. Each item contains all the replacement needed to get
   * a response for the initial request, given a particular subject for a
   * particular JSONPath replacement.
   *
   * @param \Drupal\subrequests\Subrequest $subrequest
   *   The subrequest that contains the tokens.
   * @param string $token_location
   *   Indicates if we are dealing with body or URI replacements.
   * @param \Symfony\Component\HttpFoundation\Response[] pool
   *   The collection of prior responses available for use with JSONPath.
   *
   * @returns array
   *   The structure containing a list of replacements for a subject response
   *   and a replacement candidate.
   */
  protected function extractTokenReplacements(Subrequest $subrequest, $token_location, array $pool) {
    // Turn the subject into a string.
    $regexp_subject = $token_location === 'body'
      ? Json::encode($subrequest->body)
      : $subrequest->uri;
    // First find all the replacements to do. Use a regular expression to detect
    // cases like "…{{req1.body@$.data.attributes.seasons..id}}…"
    $found = $this->findTokens($regexp_subject);
    // Then calculate the replacements we will need to return.
    $reducer = function ($token_replacements, $match) use ($pool) {
      // Remove the .body part at the end since we only support the body
      // replacement at this moment.
      $provided_id = preg_replace('/\.body$/', '', $match[1]);
      // Calculate what are the subjects to execute the JSONPath against.
      $subjects = array_filter($pool, function (Response $response) use ($provided_id) {
        // The response is considered a subject if it matches the content ID or
        // it is a generated copy based of that content ID.
        $pattern = sprintf('/%s(#.*)?/', preg_quote($provided_id));
        $content_id = $this->getContentId($response);
        return preg_match($pattern, $content_id);
      });
      if (count($subjects) === 0) {
        $candidates = array_map(function ($response) {
          $candidate = $this->getContentId($response);
          return preg_replace('/#.*/', '', $candidate);
        }, $pool);
        throw new BadRequestHttpException(sprintf(
          'Unable to find specified request for a replacement %s. Candidates are [%s].',
          $provided_id,
          implode(', ', $candidates)
        ));
      }
      // Find the replacements for this match given a subject. If there is more
      // than one response object (a subject) for a given subrequest, then we
      // generate one parallel subrequest per subject.
      foreach ($subjects as $subject) {
        $this->addReplacementsForSubject($match, $subject, $token_replacements);
      }

      return $token_replacements;
    };
    return array_reduce($found, $reducer, []);
  }

  /**
   * Gets the clean Content ID for a response.
   *
   * Removes all the derived indicators and the surrounding angles.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to extract the Content ID from.
   *
   * @returns string
   *   The content ID.
   */
  protected function getContentId(Response $response) {
    $header = $response->headers->get('Content-ID', '');
    return substr($header, 1, strlen($header) - 2);
  }

  /**
   * Finds and parses all the tokens in a given string.
   *
   * @param string $subject
   *   The tokenized string. This is usually the URI or the serialized body.
   *
   * @returns array
   *   A list of all the matches. Each match contains the token, the subject to
   *   search replacements in and the JSONPath query to execute.
   */
  protected function findTokens($subject) {
    $matches = [];
    $pattern = '/\{\{([^\{\}]+\.[^\{\}]+)@([^\{\}]+)\}\}/';
    preg_match_all($pattern, $subject, $matches);
    if (!$matches = array_filter($matches)) {
      return [];
    }
    $output = [];
    for ($index = 0; $index < count($matches[0]); $index++) {
      // We only care about the first three items: full match, subject ID and
      // JSONPath query.
      $output[] = [
        $matches[0][$index],
        $matches[1][$index],
        $matches[2][$index]
      ];
    }
    return $output;
  }

  /**
   * Fill replacement values for a subrequest a subject and an structured token.
   *
   * @param array $match
   *   The structured replacement token.
   * @param \Symfony\Component\HttpFoundation\Response $subject
   *   The response object the token refers to.
   * @param array $token_replacements
   *   The accumulated replacements. Adds items onto the array.
   */
  protected function addReplacementsForSubject(array $match, Response $subject, array &$token_replacements) {
    $json_object = new JsonObject($subject->getContent());
    $to_replace = $json_object->get($match[2]) ?: [];
    // The replacements need to be strings. If not, then the replacement
    // is not valid.
    $this->validateJsonPathReplacements($to_replace);
    // Place all the replacement items in the $token_replacements.
    foreach ($to_replace as $index => $replacement_token_value) {
      // Set the match for the Response ID + match item.
      $id_tuple = implode('::', [
        // The subject content ID. It contains the # fragment so we get
        // one per each possible subject.
        $this->getContentId($subject),
        $index,
      ]);
      $replacements_for_item = empty($token_replacements[$id_tuple]) ? [] : $token_replacements[$id_tuple];
      // The whole match string to be replaced.
      $replacements_for_item[$match[0]] = $replacement_token_value;
      $token_replacements[$id_tuple] = $replacements_for_item;
    }
  }

  /**
   * Validates tha the JSONPath query yields a string or an array of strings.
   *
   * @param array $to_replace
   *   The replacement candidates.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the replacements are not valid.
   */
  protected function validateJsonPathReplacements($to_replace) {
    $is_valid = is_array($to_replace)
      && array_reduce($to_replace, function ($valid, $replacement) {
        return $valid && (is_string($replacement) || is_int($replacement));
      }, TRUE);
    if (!$is_valid) {
      throw new BadRequestHttpException(sprintf(
        'The replacement token did find not a list of strings. Instead it found %s.',
        Json::encode($to_replace)
      ));
    }
  }

}
