# maposmatic-hiking-atlas

Create a local hiking atlas booklet using the MapOSMatic render API,
OverpassAPI queries, and GPX track information for hiking route
relations from WayMarkedTrails.

For now this is just a quick and dirty proof of concept tool to test
the render API, it works on my computer, and your mileage is most likely
going to vary.

To run this:

* look up the OSM relation ID for your citys admin boundary polygon
* edit the header part of the wanderatlas.php file
* run `php wanderatlas.php`
* wait ...

