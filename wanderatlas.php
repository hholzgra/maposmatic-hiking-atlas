<?php
require_once 'HTTP/Request2.php';

$city = "Enger";
$osmid = -147441;

define('BASE_URL', 'https://print.get-map.org/apis/v1/');
define('OVERPASS_URL', 'http://overpass-api.de/api/interpreter');
define('WAYMARKED_GPX_URL', 'https://hiking.waymarkedtrails.org/api/v1/details/relation/%s/geometry/gpx');

# === config ends here ===


$query = '[out:json];area[name="'.$city.'"];relation(area)["route"="hiking"];out;';

echo "Fetching routes from Overpass API ...";

$routes = get_routes_for_query($query);

echo " done\n";

$routes_count = count($routes);

$tmpdir = tempdir();

setlocale(LC_ALL, 'de_DE.UTF-8');

uksort($routes, "strcoll");

$pdflist = "";

$pdf = get_city_pdf($osmid, $city);
$pdf_base = basename($pdf);
copy($pdf, "$tmpdir/$pdf_base");
$pdflist .= " $tmpdir/$pdf_base";

$routes_processed = 0;
foreach ($routes as $key => $route) {
    $routes_processed++;
    printf("Route %d/%d: %s ", $routes_processed, $routes_count, $route['name']);
    $pdf = get_route_pdf($route);
    $routes[$key]['pdf_url'] = $pdf;
    $pdf_base = basename($pdf);
    $routes[$key]['pdf_file'] = "$tmpdir/$pdf_base";

    copy($pdf, "$tmpdir/$pdf_base");
    $pdflist .= " $tmpdir/$pdf_base";
}

$workdir = getcwd();

chdir($tmpdir);

system("pdfunite $pdflist $workdir/Wanderatlas-$city.pdf");

chdir($workdir);

system("rm -rf $tmpdir");

system("xdg-open Wanderatlas-$city.pdf");




function get_city_pdf($osmid, $name)
{
    echo "rendering plan for: $name ...";
    
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

    return wait_for_PDF($data->id);
}




function get_route_pdf($route)
{
    echo " rendering plan for route: ".$route['id']." ";
    
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

    return wait_for_PDF($data->id);
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

