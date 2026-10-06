<?php

/*
 * VRT now-playing endpoint
 *
 * - Programma + afbeelding: officiële VRT MAX GraphQL API
 * - Track hoofdkanalen: nuopderadio.be playlistpagina's
 * - Track De Tijdloze: radio.menu playlistpagina
 * - Fallback track: VRT GraphQL heading.description wanneer beschikbaar
 *
 * Bewust geschreven zonder moderne PHP type-declaraties zodat dit ook op
 * oudere hostingomgevingen draait.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');


if (
    isset($_SERVER['REQUEST_METHOD']) &&
    $_SERVER['REQUEST_METHOD'] !== 'GET'
) {

    http_response_code(405);

    header('Allow: GET');

    respond(
        array(
            'error' => 'method_not_allowed'
        )
    );
}


$page =
    isset($_GET['page'])
        ? trim((string) $_GET['page'])
        : '';


$page =
    '/' .
    ltrim(
        $page,
        '/'
    );


$page =
    rtrim(
        $page,
        '/'
    );


$debug =
    isset($_GET['debug']) &&
    $_GET['debug'] === '1';


/*
 * De code voor het ophalen/parsen blijft generiek.
 * Alleen de playlistbron verschilt per VRT-kanaal.
 */

$sources =
    array(

        '/kanalen/studio-brussel' =>
            array(
                'type' => 'nuop',
                'url' =>
                    'https://nuopderadio.be/stubru/'
            ),

        '/kanalen/mnm' =>
            array(
                'type' => 'nuop',
                'url' =>
                    'https://nuopderadio.be/mnm/'
            ),

        '/kanalen/klara' =>
            array(
                'type' => 'nuop',
                'url' =>
                    'https://nuopderadio.be/klara/'
            ),

        '/radio1' =>
            array(
                'type' => 'nuop',
                'url' =>
                    'https://nuopderadio.be/radio1/'
            ),

        '/radio2' =>
            array(
                'type' => 'nuop',
                'url' =>
                    'https://nuopderadio.be/radio2/'
            ),

        '/kanalen/de-tijdloze' =>
            array(
                'type' => 'radiomenu',
                'url' =>
                    'https://radio.menu/stations/stubru-be-studio-brussel-de-tijdloze/playlist/'
            )

    );


if (
    $page === '' ||
    !isset($sources[$page])
) {

    http_response_code(400);

    respond(
        array(
            'error' =>
                'invalid_page',

            'page' =>
                $page
        )
    );
}


/*
 * =========================================================
 * VRT GRAPHQL
 * =========================================================
 */

$program =
    '';


$image =
    '';


$graphqlTrack =
    null;


$graphqlError =
    '';


$graphql =
    fetchVrtGraphql(
        $page
    );


if (
    is_array(
        $graphql
    )
) {

    if (
        isset(
            $graphql['error']
        )
    ) {

        $graphqlError =
            $graphql['error'];

    }

    else {

        $program =
            isset(
                $graphql['program']
            )
                ? cleanText(
                    $graphql['program']
                )
                : '';


        $image =
            isset(
                $graphql['image']
            )
                ? cleanText(
                    $graphql['image']
                )
                : '';


        if (
            isset(
                $graphql['track']
            ) &&
            is_array(
                $graphql['track']
            )
        ) {

            $graphqlTrack =
                $graphql['track'];

        }

    }

}


/*
 * =========================================================
 * PLAYLIST TRACK
 * =========================================================
 */

$track =
    null;


$trackSource =
    '';


$playlistError =
    '';


$source =
    $sources[$page];


if (
    $source['type'] ===
    'nuop'
) {

    $result =
        fetchNuOpTrack(
            $source['url']
        );

}

else {

    $result =
        fetchRadioMenuTrack(
            $source['url']
        );

}


if (
    is_array(
        $result
    ) &&
    isset(
        $result['artist']
    ) &&
    isset(
        $result['title']
    )
) {

    $track =
        $result;


    $trackSource =
        $source['type'];

}

elseif (
    is_array(
        $result
    ) &&
    isset(
        $result['error']
    )
) {

    $playlistError =
        $result['error'];

}


/*
 * Alleen terugvallen op VRT heading.description wanneer
 * de aparte playlistbron niets oplevert.
 */

if (
    $track === null &&
    is_array(
        $graphqlTrack
    )
) {

    $track =
        $graphqlTrack;


    $trackSource =
        'vrt_graphql';

}


/*
 * =========================================================
 * RESPONSE
 * =========================================================
 */

$response =
    array(

        'page' =>
            $page,

        'program' =>
            $program,

        'image' =>
            $image

    );


if (
    $track !== null
) {

    $response['artist'] =
        $track['artist'];


    $response['title'] =
        $track['title'];


    $response['source'] =
        $trackSource;

}

else {

    $response['noTrack'] =
        true;

}


if (
    $debug
) {

    $response['debug'] =
        array(

            'playlistType' =>
                $source['type'],

            'playlistUrl' =>
                $source['url'],

            'playlistError' =>
                $playlistError,

            'graphqlError' =>
                $graphqlError,

            'phpVersion' =>
                PHP_VERSION

        );

}


respond(
    $response
);


/*
 * =========================================================
 * JSON RESPONSE
 * =========================================================
 */

function respond(
    $data
) {

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );


    exit;

}


/*
 * =========================================================
 * CLEAN TEXT
 * =========================================================
 */

function cleanText(
    $value
) {

    if (
        $value === null
    ) {

        return '';

    }


    $value =
        html_entity_decode(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );


    $value =
        preg_replace(
            '/[\x00-\x1F\x7F]/u',
            ' ',
            $value
        );


    $value =
        preg_replace(
            '/\s+/u',
            ' ',
            $value
        );


    return trim(
        $value
    );

}


/*
 * =========================================================
 * ARTIST - TITLE
 * =========================================================
 */

function splitTrack(
    $value
) {

    $value =
        cleanText(
            $value
        );


    if (
        $value === ''
    ) {

        return null;

    }


    $separators =
        array(
            ' – ',
            ' — ',
            ' - '
        );


    foreach (
        $separators
        as $separator
    ) {

        $position =
            strpos(
                $value,
                $separator
            );


        if (
            $position === false ||
            $position <= 0
        ) {

            continue;

        }


        $artist =
            cleanText(
                substr(
                    $value,
                    0,
                    $position
                )
            );


        $title =
            cleanText(
                substr(
                    $value,
                    $position +
                    strlen(
                        $separator
                    )
                )
            );


        if (
            $artist !== '' &&
            $title !== ''
        ) {

            return array(

                'artist' =>
                    $artist,

                'title' =>
                    $title

            );

        }

    }


    return null;

}


/*
 * =========================================================
 * VRT GRAPHQL
 * =========================================================
 */

function fetchVrtGraphql(
    $page
) {

    $url =
        'https://www.vrt.be/vrtnu-api/graphql/public/v1';


    $query =
<<<'GRAPHQL'
query RadioNowPlaying($pageId: ID!) {
  page(id: $pageId) {
    __typename

    ... on ChannelPage {
      brand

      heading {
        __typename

        ... on Banner {
          title
          description

          image {
            templateUrl
          }
        }
      }
    }
  }
}
GRAPHQL;


    $payload =
        json_encode(
            array(

                'operationName' =>
                    'RadioNowPlaying',

                'query' =>
                    $query,

                'variables' =>
                    array(
                        'pageId' =>
                            $page
                    )

            )
        );


    if (
        $payload === false
    ) {

        return array(
            'error' =>
                'graphql_payload_error'
        );

    }


    $result =
        httpRequest(

            $url,

            'POST',

            array(
                'Accept: application/json',
                'Content-Type: application/json',
                'X-VRT-CLIENT-NAME: WEB'
            ),

            $payload,

            6

        );


    if (
        !is_array(
            $result
        ) ||
        !$result['ok']
    ) {

        return array(
            'error' =>
                'graphql_request_failed'
        );

    }


    $data =
        json_decode(
            $result['body'],
            true
        );


    if (
        !is_array(
            $data
        )
    ) {

        return array(
            'error' =>
                'graphql_invalid_json'
        );

    }


    if (
        !empty(
            $data['errors']
        )
    ) {

        return array(
            'error' =>
                'graphql_api_error'
        );

    }


    if (
        !isset(
            $data['data']['page']
        ) ||
        !is_array(
            $data['data']['page']
        )
    ) {

        return array(
            'error' =>
                'graphql_page_missing'
        );

    }


    $pageData =
        $data['data']['page'];


    $heading =
        isset(
            $pageData['heading']
        ) &&
        is_array(
            $pageData['heading']
        )
            ? $pageData['heading']
            : array();


    $program =
        isset(
            $heading['title']
        )
            ? cleanText(
                $heading['title']
            )
            : '';


    $description =
        isset(
            $heading['description']
        )
            ? cleanText(
                $heading['description']
            )
            : '';


    $image =
        '';


    if (
        isset(
            $heading['image']
        ) &&
        is_array(
            $heading['image']
        ) &&
        isset(
            $heading['image']['templateUrl']
        )
    ) {

        $image =
            cleanText(
                $heading['image']['templateUrl']
            );

    }


    return array(

        'program' =>
            $program,

        'image' =>
            $image,

        'track' =>
            splitTrack(
                $description
            )

    );

}


/*
 * =========================================================
 * NUOPDERADIO.BE
 * =========================================================
 */

function fetchNuOpTrack(
    $url
) {

    $result =
        httpRequest(

            $url,

            'GET',

            array(
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8'
            ),

            null,

            6

        );


    if (
        !is_array(
            $result
        ) ||
        !$result['ok'] ||
        $result['body'] === ''
    ) {

        return array(
            'error' =>
                'playlist_request_failed'
        );

    }


    /*
     * DOM-extensie niet beschikbaar?
     * Dan gebruiken we een tekstfallback.
     */

    if (
        !class_exists(
            'DOMDocument'
        )
    ) {

        return parseNuOpWithoutDom(
            $result['body']
        );

    }


    $dom =
        new DOMDocument();


    $previous =
        libxml_use_internal_errors(
            true
        );


    /*
     * XML encoding hint voorkomt dat UTF-8 tekens
     * zoals de en-dash verkeerd worden geïnterpreteerd.
     */

    $loaded =
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' .
            $result['body']
        );


    libxml_clear_errors();


    libxml_use_internal_errors(
        $previous
    );


    if (
        !$loaded
    ) {

        return array(
            'error' =>
                'playlist_html_invalid'
        );

    }


    $xpath =
        new DOMXPath(
            $dom
        );


    $nodes =
        $xpath->query(
            '//main//p'
        );


    if (
        !$nodes ||
        $nodes->length < 2
    ) {

        return array(
            'error' =>
                'playlist_items_missing'
        );

    }


    for (
        $i = 0;
        $i < $nodes->length - 1;
        $i++
    ) {

        $time =
            cleanText(
                $nodes
                    ->item($i)
                    ->textContent
            );


        if (
            !preg_match(
                '/^\d{1,2}:\d{2}$/',
                $time
            )
        ) {

            continue;

        }


        /*
         * Op nuopderadio is het eerstvolgende <p>
         * na het tijdstip de actuele track of een niet-muziekitem.
         */

        $candidate =
            cleanText(
                $nodes
                    ->item($i + 1)
                    ->textContent
            );


        $track =
            splitTrack(
                $candidate
            );


        if (
            $track !== null
        ) {

            return $track;

        }


        /*
         * Het eerste tijdstip is de actuele regel.
         * Als daar bijvoorbeeld Nieuws staat, tonen we geen
         * vorige track als ware die nog actueel.
         */

        return array(
            'error' =>
                'current_item_is_not_track'
        );

    }


    return array(
        'error' =>
            'playlist_current_item_missing'
    );

}


/*
 * =========================================================
 * NUOP FALLBACK ZONDER DOMDOCUMENT
 * =========================================================
 */

function parseNuOpWithoutDom(
    $html
) {

    $html =
        preg_replace(
            '/<br\s*\/?\s*>/i',
            "\n",
            $html
        );


    $html =
        preg_replace(
            '/<\/p\s*>/i',
            "\n",
            $html
        );


    $text =
        html_entity_decode(
            strip_tags(
                $html
            ),
            ENT_QUOTES,
            'UTF-8'
        );


    $lines =
        preg_split(
            '/\r?\n/',
            $text
        );


    $clean =
        array();


    foreach (
        $lines
        as $line
    ) {

        $line =
            cleanText(
                $line
            );


        if (
            $line !== ''
        ) {

            $clean[] =
                $line;

        }

    }


    for (
        $i = 0;
        $i < count($clean) - 1;
        $i++
    ) {

        if (
            !preg_match(
                '/^\d{1,2}:\d{2}$/',
                $clean[$i]
            )
        ) {

            continue;

        }


        $parts =
            array();


        for (
            $j = $i + 1;
            $j < count($clean);
            $j++
        ) {

            if (
                preg_match(
                    '/^\d{1,2}:\d{2}$/',
                    $clean[$j]
                ) ||
                stripos(
                    $clean[$j],
                    'Album:'
                ) === 0
            ) {

                break;

            }


            $parts[] =
                $clean[$j];

        }


        $track =
            splitTrack(
                implode(
                    ' ',
                    $parts
                )
            );


        if (
            $track !== null
        ) {

            return $track;

        }


        return array(
            'error' =>
                'current_item_is_not_track'
        );

    }


    return array(
        'error' =>
            'playlist_current_item_missing'
    );

}


/*
 * =========================================================
 * RADIO.MENU / DE TIJDLOZE
 * =========================================================
 */

function fetchRadioMenuTrack(
    $url
) {

    $result =
        httpRequest(

            $url,

            'GET',

            array(
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: en-US,en;q=0.9,nl;q=0.8'
            ),

            null,

            6

        );


    if (
        !is_array(
            $result
        ) ||
        !$result['ok'] ||
        $result['body'] === ''
    ) {

        return array(
            'error' =>
                'playlist_request_failed'
        );

    }


    if (
        !class_exists(
            'DOMDocument'
        )
    ) {

        return parseRadioMenuWithoutDom(
            $result['body']
        );

    }


    $dom =
        new DOMDocument();


    $previous =
        libxml_use_internal_errors(
            true
        );


    $loaded =
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' .
            $result['body']
        );


    libxml_clear_errors();


    libxml_use_internal_errors(
        $previous
    );


    if (
        !$loaded
    ) {

        return array(
            'error' =>
                'playlist_html_invalid'
        );

    }


    $xpath =
        new DOMXPath(
            $dom
        );


    $nodes =
        $xpath->query(
            '//main//ul/li'
        );


    if (
        !$nodes ||
        $nodes->length === 0
    ) {

        return array(
            'error' =>
                'playlist_items_missing'
        );

    }


    /*
     * De Tijdloze zet regelmatig een stationsjingle als
     * nieuwste regel:
     *
     * 11:54 De Tijdloze
     * 11:49 QUEEN - Another one bites the dust
     *
     * Daarom bekijken we maximaal zes recente items
     * en pakken we de eerste echte ARTIST - TITLE-regel.
     */

    $limit =
        min(
            6,
            $nodes->length
        );


    for (
        $i = 0;
        $i < $limit;
        $i++
    ) {

        $node =
            $nodes->item(
                $i
            );


        $text =
            cleanText(
                $node->textContent
            );


        $text =
            preg_replace(
                '/^\d{1,2}:\d{2}\s*/',
                '',
                $text
            );


        $text =
            cleanText(
                $text
            );


        if (
            $text === '' ||
            preg_match(
                '/^(De Tijdloze|VRT Studio Brussel De Tijdloze)$/i',
                $text
            )
        ) {

            continue;

        }


        $track =
            splitTrack(
                $text
            );


        if (
            $track !== null
        ) {

            return $track;

        }

    }


    return array(
        'error' =>
            'playlist_current_track_missing'
    );

}


/*
 * =========================================================
 * RADIO.MENU FALLBACK ZONDER DOMDOCUMENT
 * =========================================================
 */

function parseRadioMenuWithoutDom(
    $html
) {

    if (
        !preg_match_all(
            '/<li[^>]*>(.*?)<\/li>/is',
            $html,
            $matches
        )
    ) {

        return array(
            'error' =>
                'playlist_items_missing'
        );

    }


    $limit =
        min(
            6,
            count(
                $matches[1]
            )
        );


    for (
        $i = 0;
        $i < $limit;
        $i++
    ) {

        $text =
            html_entity_decode(
                strip_tags(
                    $matches[1][$i]
                ),
                ENT_QUOTES,
                'UTF-8'
            );


        $text =
            cleanText(
                $text
            );


        $text =
            preg_replace(
                '/^\d{1,2}:\d{2}\s*/',
                '',
                $text
            );


        $text =
            cleanText(
                $text
            );


        if (
            $text === '' ||
            preg_match(
                '/^(De Tijdloze|VRT Studio Brussel De Tijdloze)$/i',
                $text
            )
        ) {

            continue;

        }


        $track =
            splitTrack(
                $text
            );


        if (
            $track !== null
        ) {

            return $track;

        }

    }


    return array(
        'error' =>
            'playlist_current_track_missing'
    );

}


/*
 * =========================================================
 * HTTP REQUEST
 * =========================================================
 */

function httpRequest(
    $url,
    $method,
    $headers,
    $body,
    $timeout
) {

    $userAgent =
        'rc045-radio/1.0 (+https://rc045.nl/radio.html)';


    /*
     * Eerst cURL.
     */

    if (
        function_exists(
            'curl_init'
        )
    ) {

        $ch =
            curl_init(
                $url
            );


        if (
            $ch === false
        ) {

            return array(
                'ok' =>
                    false,

                'body' =>
                    '',

                'status' =>
                    0
            );

        }


        $options =
            array(

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_FOLLOWLOCATION =>
                    true,

                CURLOPT_MAXREDIRS =>
                    3,

                CURLOPT_CONNECTTIMEOUT =>
                    3,

                CURLOPT_TIMEOUT =>
                    $timeout,

                CURLOPT_USERAGENT =>
                    $userAgent,

                CURLOPT_HTTPHEADER =>
                    $headers,

                CURLOPT_ENCODING =>
                    ''

            );


        if (
            $method === 'POST'
        ) {

            $options[
                CURLOPT_POST
            ] =
                true;


            $options[
                CURLOPT_POSTFIELDS
            ] =
                $body;

        }


        curl_setopt_array(
            $ch,
            $options
        );


        $response =
            curl_exec(
                $ch
            );


        $status =
            (int)
            curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


        $errno =
            curl_errno(
                $ch
            );


        curl_close(
            $ch
        );


        return array(

            'ok' =>
                (
                    $response !== false &&
                    $errno === 0 &&
                    $status >= 200 &&
                    $status < 300
                ),

            'body' =>
                (
                    $response === false
                        ? ''
                        : $response
                ),

            'status' =>
                $status

        );

    }


    /*
     * Hosting zonder cURL:
     * fallback naar file_get_contents.
     */

    $headerText =
        implode(
            "\r\n",
            $headers
        );


    $headerText .=
        "\r\nUser-Agent: " .
        $userAgent;


    $options =
        array(

            'http' =>
                array(

                    'method' =>
                        $method,

                    'header' =>
                        $headerText,

                    'timeout' =>
                        $timeout,

                    'ignore_errors' =>
                        true

                )

        );


    if (
        $method === 'POST'
    ) {

        $options['http']['content'] =
            $body;

    }


    $context =
        stream_context_create(
            $options
        );


    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );


    $status =
        0;


    if (
        isset(
            $http_response_header
        ) &&
        is_array(
            $http_response_header
        )
    ) {

        foreach (
            $http_response_header
            as $headerLine
        ) {

            if (
                preg_match(
                    '#^HTTP/\S+\s+(\d{3})#',
                    $headerLine,
                    $match
                )
            ) {

                $status =
                    (int) $match[1];


                break;

            }

        }

    }


    return array(

        'ok' =>
            (
                $response !== false &&
                $status >= 200 &&
                $status < 300
            ),

        'body' =>
            (
                $response === false
                    ? ''
                    : $response
            ),

        'status' =>
            $status

    );

}
