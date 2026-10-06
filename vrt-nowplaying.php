<?php

declare(strict_types=1);

/*
 * =========================================================
 * VRT GENERIC NOW PLAYING
 * =========================================================
 *
 * Eén endpoint voor alle VRT-radiozenders die via een
 * VRT MAX ChannelPage hun now-playing informatie aanbieden.
 *
 * Voorbeelden:
 *
 * /vrt-nowplaying.php?page=/kanalen/studio-brussel
 * /vrt-nowplaying.php?page=/kanalen/mnm
 * /vrt-nowplaying.php?page=/kanalen/klara
 * /vrt-nowplaying.php?page=/kanalen/de-tijdloze
 * /vrt-nowplaying.php?page=/radio1
 * /vrt-nowplaying.php?page=/radio2
 *
 * Dit is GEEN algemene CORS-proxy.
 *
 * Het endpoint kan uitsluitend:
 *
 * - de vaste VRT GraphQL API benaderen;
 * - een gecontroleerd VRT page-id meesturen;
 * - now-playing informatie teruggeven.
 *
 * Er kan geen externe URL via dit endpoint worden opgevraagd.
 */


/* =========================================================
 * RESPONSE HEADERS
 * ========================================================= */

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, max-age=0'
);

header(
    'Pragma: no-cache'
);

header(
    'X-Content-Type-Options: nosniff'
);


/* =========================================================
 * ALLEEN GET TOESTAAN
 * ========================================================= */

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
) {

    http_response_code(405);

    header(
        'Allow: GET'
    );

    echo json_encode(
        [
            'error' => 'method_not_allowed',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * PAGE-ID OPHALEN
 * ========================================================= */

$requestedPage =
    isset($_GET['page'])
        ? trim((string) $_GET['page'])
        : '';


if (
    $requestedPage === ''
) {

    http_response_code(400);

    echo json_encode(
        [
            'error' => 'missing_page',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
 * URL-decoding gebeurt normaal al door PHP.
 *
 * Voor de zekerheid verwijderen we uitsluitend overbodige
 * whitespace en maken we het pad consistent.
 */

$requestedPage =
    '/' . ltrim(
        $requestedPage,
        '/'
    );


/*
 * Trailing slash verwijderen.
 *
 * /kanalen/mnm/
 *
 * wordt:
 *
 * /kanalen/mnm
 */

if (
    strlen($requestedPage) > 1
) {

    $requestedPage =
        rtrim(
            $requestedPage,
            '/'
        );
}


/* =========================================================
 * PAGE-ID VALIDEREN
 * =========================================================
 *
 * We staan bewust niet ieder willekeurig VRT MAX-pad toe.
 *
 * Geldig:
 *
 * /radio1
 * /radio2
 * /kanalen/studio-brussel
 * /kanalen/mnm
 * /kanalen/klara
 * /kanalen/de-tijdloze
 * /kanalen/radio-bene
 * etc.
 *
 * Niet geldig:
 *
 * http://...
 * https://...
 * /vrtmax/...
 * ../...
 * vreemde queryconstructies
 */


/*
 * Radio 1 en Radio 2 gebruiken bij VRT afwijkende,
 * korte channel-page-id's.
 */

$isMainRadioPage =
    preg_match(
        '#^/radio[12]$#',
        $requestedPage
    ) === 1;


/*
 * De overige VRT-zenders zitten normaal onder:
 *
 * /kanalen/<slug>
 *
 * Alleen kleine letters, cijfers en koppeltekens.
 */

$isChannelPage =
    preg_match(
        '#^/kanalen/[a-z0-9][a-z0-9-]{0,79}$#',
        $requestedPage
    ) === 1;


if (
    !$isMainRadioPage &&
    !$isChannelPage
) {

    http_response_code(400);

    echo json_encode(
        [
            'error' => 'invalid_page',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * VRT GRAPHQL
 * ========================================================= */

const VRT_GRAPHQL_URL =
    'https://www.vrt.be/vrtnu-api/graphql/public/v1';


/*
 * Dezelfde query werkt voor iedere VRT ChannelPage.
 *
 * heading.title:
 * huidig radioprogramma.
 *
 * heading.description:
 * huidige track in de vorm:
 *
 * ARTIEST - TITEL
 *
 * Tijdens nieuws, jingles of presentaties kan description
 * leeg zijn.
 */

$query = <<<'GRAPHQL'
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


/* =========================================================
 * GRAPHQL PAYLOAD
 * ========================================================= */

$payload =
    json_encode(
        [
            'operationName' =>
                'RadioNowPlaying',

            'query' =>
                $query,

            'variables' =>
                [
                    'pageId' =>
                        $requestedPage,
                ],
        ],
        JSON_UNESCAPED_SLASHES
    );


if (
    $payload === false
) {

    http_response_code(500);

    echo json_encode(
        [
            'error' => 'payload_error',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * CURL INITIALISEREN
 * ========================================================= */

$curl =
    curl_init(
        VRT_GRAPHQL_URL
    );


if (
    $curl === false
) {

    http_response_code(500);

    echo json_encode(
        [
            'error' => 'curl_init_failed',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * CURL CONFIGURATIE
 * ========================================================= */

curl_setopt_array(
    $curl,
    [
        CURLOPT_POST =>
            true,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_CONNECTTIMEOUT =>
            5,

        CURLOPT_TIMEOUT =>
            8,

        CURLOPT_FOLLOWLOCATION =>
            false,

        CURLOPT_MAXREDIRS =>
            0,

        CURLOPT_HTTPHEADER =>
            [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-VRT-CLIENT-NAME: WEB',
            ],

        CURLOPT_POSTFIELDS =>
            $payload,
    ]
);


/* =========================================================
 * REQUEST UITVOEREN
 * ========================================================= */

$response =
    curl_exec(
        $curl
    );


$httpStatus =
    (int) curl_getinfo(
        $curl,
        CURLINFO_RESPONSE_CODE
    );


$curlErrorNumber =
    curl_errno(
        $curl
    );


curl_close(
    $curl
);


/* =========================================================
 * NETWERKFOUT
 * ========================================================= */

if (
    $response === false ||
    $curlErrorNumber !== 0
) {

    http_response_code(502);

    echo json_encode(
        [
            'error' =>
                'vrt_connection_failed',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * HTTP STATUS CONTROLEREN
 * ========================================================= */

if (
    $httpStatus < 200 ||
    $httpStatus >= 300
) {

    http_response_code(502);

    echo json_encode(
        [
            'error' =>
                'vrt_request_failed',

            'status' =>
                $httpStatus,
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * JSON DECODEN
 * ========================================================= */

$data =
    json_decode(
        $response,
        true
    );


if (
    !is_array($data)
) {

    http_response_code(502);

    echo json_encode(
        [
            'error' =>
                'invalid_vrt_json',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * GRAPHQL ERRORS
 * ========================================================= */

if (
    !empty(
        $data['errors']
    )
) {

    http_response_code(502);

    echo json_encode(
        [
            'error' =>
                'vrt_graphql_error',
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * PAGE
 * ========================================================= */

$page =
    $data['data']['page']
        ?? null;


if (
    !is_array($page)
) {

    http_response_code(404);

    echo json_encode(
        [
            'error' =>
                'vrt_page_not_found',

            'page' =>
                $requestedPage,
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * CHANNELPAGE CONTROLEREN
 * ========================================================= */

$pageType =
    trim(
        (string) (
            $page['__typename']
                ?? ''
        )
    );


if (
    $pageType !== 'ChannelPage'
) {

    http_response_code(422);

    echo json_encode(
        [
            'error' =>
                'unsupported_vrt_page',

            'pageType' =>
                $pageType,

            'page' =>
                $requestedPage,
        ],
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * HEADING
 * ========================================================= */

$heading =
    $page['heading']
        ?? null;


if (
    !is_array($heading)
) {

    echo json_encode(
        [
            'noTrack' =>
                true,

            'page' =>
                $requestedPage,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * PROGRAMMA
 * ========================================================= */

$program =
    trim(
        (string) (
            $heading['title']
                ?? ''
        )
    );


/* =========================================================
 * DESCRIPTION
 * ========================================================= */

$description =
    trim(
        (string) (
            $heading['description']
                ?? ''
        )
    );


/* =========================================================
 * AFBEELDING
 * ========================================================= */

$image =
    trim(
        (string) (
            $heading['image']['templateUrl']
                ?? ''
        )
    );


/* =========================================================
 * GEEN TRACK
 * =========================================================
 *
 * Tijdens:
 *
 * - nieuws;
 * - presentator;
 * - jingles;
 * - reclame;
 * - overgang tussen nummers;
 *
 * kan VRT description leeg laten.
 */

if (
    $description === ''
) {

    echo json_encode(
        [
            'noTrack' =>
                true,

            'program' =>
                $program,

            'image' =>
                $image,

            'page' =>
                $requestedPage,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * ARTIEST - TITEL SPLITSEN
 * ========================================================= */

$separator =
    strpos(
        $description,
        ' - '
    );


/*
 * Niet proberen zelf te gokken als VRT een andere tekstvorm
 * terugstuurt.
 */

if (
    $separator === false
) {

    echo json_encode(
        [
            'noTrack' =>
                true,

            'program' =>
                $program,

            'image' =>
                $image,

            'page' =>
                $requestedPage,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * ARTIEST
 * ========================================================= */

$artist =
    trim(
        substr(
            $description,
            0,
            $separator
        )
    );


/* =========================================================
 * TITEL
 * ========================================================= */

$title =
    trim(
        substr(
            $description,
            $separator + 3
        )
    );


/* =========================================================
 * LEGE WAARDEN
 * ========================================================= */

if (
    $artist === '' ||
    $title === ''
) {

    echo json_encode(
        [
            'noTrack' =>
                true,

            'program' =>
                $program,

            'image' =>
                $image,

            'page' =>
                $requestedPage,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
 * SUCCES
 * ========================================================= */

echo json_encode(
    [
        'artist' =>
            $artist,

        'title' =>
            $title,

        'program' =>
            $program,

        'image' =>
            $image,

        'page' =>
            $requestedPage,
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);
