#! /bin/env php
<?php
require_once 'HTTP/Request2.php';

$prefix = "Wanderwege ";
$city = "Oldinghausen";
$osmid = -4220331;

define('MAPOSMATIC_SERVER', 'http://localhost:8000/');
define('OVERPASS_SERVER',   'http://overpass-api.de/');
define('WAYMARKED_SERVER',  'https://hiking.waymarkedtrails.org/');

setlocale(LC_ALL, 'de_DE.UTF-8');

$wait_period = 15;

# === config ends here ===


// Base URLs of different web services we use
define('BASE_URL',          MAPOSMATIC_SERVER . 'apis/v1/');
define('OVERPASS_URL',      OVERPASS_SERVER   . '/api/interpreter');
define('WAYMARKED_GPX_URL', WAYMARKED_SERVER  . '/api/v1/details/relation/%s/geometry/gpx');

// Overpass query

echo "Fetching routes from Overpass API ...";

$query = overpass_query_for_routes_in_area($osmid, "hiking");
$routes = get_routes_for_query($query);

echo " done\n";

$routes_count = count($routes);

echo "$routes_count routes found\n";

// temporary directory to store all retrieved and generated files in
$tmpdir = tempdir();
echo "Tmpdir is: $tmpdir\n";

// sort routes by name
uksort($routes, "strcoll");


$requests = [];

// first file request for the overview page
$title = "$prefix $city";
$id = request_city($osmid, $title);
$requests[$id] = ["name" => $title];

// then file individual requests for each route
$routes_processed = 0;
foreach ($routes as $key => $route) {
    $route['name'];
    $routes_processed++;
    printf("Requesting route %d/%d: %s\n", $routes_processed, $routes_count, $route['name']);
    $id = request_route($route);
    $requests[$id] = ["name" => $route['name']];
}

// now wait for all the rendering requests to complete
// and retrieve the resulting PDF files
$pending_requests = count($requests);
while ($pending_requests) {
    echo "... waiting for $pending_requests requests to complete   \n";
    foreach($requests as $id => $data) {
        if (isset($requests[$id]["pdf"]))
            continue;

        $pdf = check_for_pdf($id);
        if ($pdf) {
            --$pending_requests;
            $pdf_target = "$tmpdir/".basename($pdf);
            copy($pdf, $pdf_target);
            $requests[$id]["pdf"] = $pdf_target;
        } else {
            // no need to check the remaining requests yet
            // even if we eventually get parallel processing
            // on the server side serialized waiting is OK
            // to minimize HTTP request count, we can only
            // proceed after *all* requets have completed anyway
            break;
        }
    }
    sleep($wait_period);
}
echo "\n";

create_booklet($requests, "my-atlas.pdf", $tmpdir);

system("rm -rf $tmpdir");

echo "Launching PDF viewer\n";
system("xdg-open my-atlas.pdf");



/**
 * File render request for a whole city
 *
 * Files a request to render a specific adminstrative
 * boundary -- usually a city -- by giving the boudary
 * polygon OSM id and a title to put in the title bar.
 *
 * Optionally the paper format and the route overlay
 * to use can be specified, too, e.g. to create a
 * biking route atlas instead.
 *
 * OSM IDs are positive numbers when the admin boundary
 * is a simple way, in the more common case that it is
 * a relation combining multiple ways the ID is the
 * OSM ID of the relation as a negative number.
 *
 * There is no city name lookup happening here, the
 * title parameter is used for the page title only.
 *
 * @param int    $osmid
 * @param string $title (optional) PDF Title
 * @param string $paper_size (optional) defaults to DinA4
 * @param string $overlay (optional) defaults to hiking route overlay
 * @return int render job ID
 */
function request_city($osmid, $title = 'Wanderatlas', $paper_size='Din A4', $overlay = 'WayMarkedHiking-Overlay')
{
    echo "Requestiong overview plan for: $title\n";

    // create request data structure
    $data = ['osmid'      => $osmid,
             'title'      => $title,
             'paper_size' => $paper_size,
             'overlays'   => $overlay,
            ];

    // submit the request
    $request = new HTTP_Request2(BASE_URL . "jobs");
    $request->setMethod(HTTP_Request2::METHOD_POST)
            ->setHeader('Content-type: application/json; charset=utf-8')
            ->setBody(json_encode($data));
    $response = $request->send();

    // get and verify status
    $status   = $response->getStatus();
    if ($status != 200 && $status != 202) {
        echo "unexpected status: $status\n";
        echo $response->getBody();
        exit;
    }

    // extract and return render job ID
    $data = json_decode($response->getBody());
    return $data->id;
}

/**
 * File render request for a specific route
 *
 * @param string $route URL of a GPX format route to render
 * @return int render job ID
 */
function request_route($route)
{
    $data = ['paper_size' => 'Din A4',
             'track_url'  => $route['gpx_url']
            ];

    $request = new HTTP_Request2(BASE_URL . "jobs");

    $request->setMethod(HTTP_Request2::METHOD_POST)
            ->setHeader('Content-type: application/json; charset=utf-8')
            ->setBody(json_encode($data));
    $response = $request->send();
    $status   = $response->getStatus();

    if ($status != 200 && $status != 202) {
        echo "unexpected status: $status\n";
        echo $response->getBody();
        exit;
    }

    $data = json_decode($response->getBody());
    return $data->id;
}

/**
 * Check whether job rendering completed and return PDF if so
 *
 * @param  int $id render job ID to check for
 * @return mixed   PDF file URL as string if ready, else null
 */
function check_for_PDF($id)
{
    $job_url = "/jobs/" . $id;

    list($status, $reply) = api_GET($job_url);

    if ($status != 200 && $status != 202) {
        echo "unexpected status: $status\n";
        print_r($reply);
        exit;
    }

    return ($status == 200) ? $reply->files->pdf : null;
}

/**
 * Perform API GET request and return processed result
 *
 * @param strig $url relative API URL
 * @return array
 */
function api_GET($url)
{
  $request = new HTTP_Request2(BASE_URL . $url);

  $request->setMethod(HTTP_Request2::METHOD_GET);

  $response = $request->send();
  $status   = $response->getStatus();

  if ($status < 200 || $status > 299) {
	  echo "invalid HTTP response to '$url': $status\n";
	  echo $response->getBody();
	  exit(3);
  }

  return array($status, json_decode($response->getBody()));
}



/**
 * Create a temporary directory
 *
 * Creates a randomly named temporary directory
 * under the systems temporary directory (e.g. /tmp)
 * and returns the absolute path of said directory.
 *
 * @param void
 * @return string
 */
function tempdir() {
    // PHP only has a function to create a unique
    // temporary file, not a directory, so we
    // let it create a file, then immediately
    // delete it and create a directory by the
    // same name
    $tempfile=tempnam(sys_get_temp_dir(),'');
    unlink($tempfile);
    if (mkdir($tempfile)) {
        return $tempfile;
    }
    return false;
}


function get_routes_for_query($query) {
    // set up and execute API query request
    $request = new HTTP_Request2(OVERPASS_URL);
    $request->setMethod(HTTP_Request2::METHOD_POST)
            ->addPostParameter('data', $query);
    $response = $request->send();
    $status   = $response->getStatus();

    // TODO error checking

    $routes = [];

    foreach (json_decode($response->getBody())->elements as $element) {
        if (isset($element->tags->name)) {
            $name = $element->tags->name;
        } else if (isset($element->tags->ref)) {
            $name = $element->tags->ref;
            echo "nameless route, ref $name\n";
        } else {
            echo "no name or ref for ".$element->id."\n";
            continue;
        }

        $routes[$name] = [
            'id'      => $element->id,
            'name'    => $name,
            'gpx_url' => sprintf(WAYMARKED_GPX_URL, $element->id)
        ];
    }

    return $routes;
}


/**
 * Returns OverpassAPI query string for routes in an area
 *
 * @param int $osmid OSM id of area, positive for ways, negative for relations
 * @param string $route_type (optional) type of routes we're looking for
 * @return string OverpassAPI query string
 */
function overpass_query_for_routes_in_area($osmid, $route_type = "hiking")
{
    $query_template = '
[out:json];
%s(%d);
map_to_area->.region;
relation(area.region)["route"="%s"];
out;
';

    return sprintf($query_template,
                   ($osmid < 0) ? 'rel' : 'way',
                   abs($osmid),
                   $route_type);
}

/**
 * Combine PDFs into booklet
 *
 * Stitch all the invdividually rendered pages into one
 * combined PDF booklet, adding page numbers and a table
 * of contents, using good old LaTeX
 *
 * @param array  $requests rendered requests to combine
 * @param string $filename output PDF filename
 * @param string $tmpdir   create all intermediate files here
 * @return boolean
 */
function create_booklet($requests, $filename, $tmpdir)
{
    $previous_workdir = getcwd();
    chdir($tmpdir);

    $basename = basename($filename, ".pdf");
    $texname  = "$basename.tex";
    $texfile = fopen($texname, "w");

    // LaTeX document header
    fwrite($texfile, "\\documentclass{book}

\\usepackage{pdfpages}
\\usepackage{hyperref}
\\usepackage[footskip=4cm]{geometry}

\\pagestyle{headings}

\\begin{document}

");

    // First result becomes the title page
    $data = array_shift($requests);
    $name = $data["name"];
    $pdf  = $data["pdf"];
    fprintf($texfile, "%s ---- Title: %s ---- \n", "%", $name);
    fprintf($texfile, "\\includepdf{%s}\n", $pdf);

    // Table of contents page
    fprintf($texfile, "%s ---- Table of Contents: ---- \n", "%");
    fprintf($texfile, "
\\begingroup
\\let\\clearpage\\relax
\\tableofcontents
\\endgroup
");

    // and now the individual route pages
    foreach ($requests as $id => $data) {
        $name = $data["name"];
        $pdf  = $data["pdf"];

        fprintf($texfile, "%s ---- Page: %s ---- \n", "%", $name);
        fprintf($texfile, "\\phantomsection\n");
        fprintf($texfile, "\\addcontentsline{toc}{section}{%s}\n", $name);
        fprintf($texfile, "\\includepdf[scale=0.9,pagecommand={\\thispagestyle{plain}}]{%s}\n", $pdf);
    }

    fwrite($texfile, "\\end{document}");

    // LaTeX file complete
    fclose($texfile);

    // run twice to get Table of Contents numbering
    system("pdflatex $texname >/dev/null </dev/null");
    system("pdflatex $texname >/dev/null </dev/null");

    copy($filename, "$previous_workdir/$filename");

    chdir($previous_workdir);
    return true;
}

/**
 * Escape LaTeX special characters
 *
 * @param string $string input string
 * @return string escaped output string
 */
function latex_escape( $string )
{
    $from = [ "\\",              "{",   "}",    "#",   "$",
              "%",   "&",   "~",     "_",   "^"];

    $to   = [ "\\textbackslash", "\\{", "\\}" , "\\#", "\\$",
              "\\%", "\\&", "\\~{}", "\\_", "\\^{}"];
    
    return str_replace($from, $to, $string);
}

