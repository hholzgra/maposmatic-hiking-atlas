<?php
require_once 'HTTP/Request2.php';

$city = "Enger";
$osmid = -147441;

define('MAPOSMATIC_SERVER', 'http://localhost:8000/');
define('OVERPASS_SERVER',   'http://overpass-api.de/');
define('WAYMARKED_SERVER',  'https://hiking.waymarkedtrails.org/');

setlocale(LC_ALL, 'de_DE.UTF-8');

# === config ends here ===



define('BASE_URL',          MAPOSMATIC_SERVER . 'apis/v1/');
define('OVERPASS_URL',      OVERPASS_SERVER   . '/api/interpreter');
define('WAYMARKED_GPX_URL', WAYMARKED_SERVER  . '/api/v1/details/relation/%s/geometry/gpx');

$query = '[out:json];area[name="'.$city.'"];relation(area)["route"="hiking"];out;';

echo "Fetching routes from Overpass API ...";

$routes = get_routes_for_query($query);

echo " done\n";

$routes_count = count($routes);

$tmpdir = tempdir();

uksort($routes, "strcoll");

$requests = [];

$id = request_city($osmid, $city);
$requests[] = $id;

$routes_processed = 0;
foreach ($routes as $key => $route) {
    $routes_processed++;
    printf("Requesting route %d/%d: %s\n", $routes_processed, $routes_count, $route['name']);
    $id = request_route($route);
    $requests[] = $id;
}

$pdflist = "";

while (count($requests)) {
    echo "... waiting for ".count($requests)." requests to complete   \n";
    foreach($requests as $key => $id) {
        $pdf = check_for_pdf($id);
        if ($pdf) {
            unset($requests[$key]);

            $pdf_base = basename($pdf);
            copy($pdf, "$tmpdir/$pdf_base");
            $pdflist .= " $tmpdir/$pdf_base";
        }
    }
    sleep(15);
}
echo "\n";


echo "Stitching pages together\n";
$workdir = getcwd();

chdir($tmpdir);

system("pdfunite $pdflist $workdir/Wanderatlas-$city.pdf");

chdir($workdir);

system("rm -rf $tmpdir");

echo "Launching PDF viewer\n";
system("xdg-open Wanderatlas-$city.pdf");




function request_city($osmid, $name)
{
    echo "Rendering overview plan for: $name\n";
    
    $data = ['paper_size' => 'Din A4',
             'title'      => "Wanderatlas $name",
             'overlays'   => 'WayMarkedHiking-Overlay',
             'osmid'      => $osmid
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

function get_city_pdf($osmid, $name)
{
    $id = request_city($osmid, $name);
    return wait_for_PDF($id);
}




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

function get_route_pdf($route)
{
    $id = request_route($route);
    return wait_for_PDF($id);
}

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

function wait_for_PDF($id)
{
    $job_url = "/jobs/" . $id;

    $status = 202;
    
    while ($status != 200) {
        echo ".";
        sleep(15);
        list($status, $reply) = api_GET($job_url);
        
        if ($status != 200 && $status != 202) {
            echo "unexpected status: $status\n";
            print_r($reply);
            exit;
        }
    }
    echo " done\n";

    return $reply->files->pdf;
}

function api_GET($url) 
{
  $request = new HTTP_Request2(BASE_URL . $url);

  $request->setMethod(HTTP_Request2::METHOD_GET);
  
  $response = $request->send();
  $status = $response->getStatus();

  if ($status < 200 || $status > 299) {
	  echo "invalid HTTP response to '$url': $status\n";
	  echo $response->getBody();
	  exit(3);
  }

  return array($status, json_decode($response->getBody()));
}



function tempdir() {
    $tempfile=tempnam(sys_get_temp_dir(),'');
    // you might want to reconsider this line when using this snippet.
    // it "could" clash with an existing directory and this line will
    // try to delete the existing one. Handle with caution.
    if (file_exists($tempfile)) { unlink($tempfile); }
    mkdir($tempfile);
    if (is_dir($tempfile)) { return $tempfile; }
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
            print_r($element);
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

