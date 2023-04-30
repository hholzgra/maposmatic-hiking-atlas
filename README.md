# maposmatic-hiking-atlas

Create a local hiking atlas booklet using the MapOSMatic render API,
OverpassAPI queries, and GPX track information for hiking route
relations from WayMarkedTrails.

[Demo output PDF](https://github.com/hholzgra/maposmatic-hiking-atlas/raw/main/demo-atlas.pdf)

For now this is just a quick and dirty proof of concept tool to test
the render API, it works on my computer, and your mileage may vary.

Prerequisites:

* PHP >= 7.x
* Pear package HttpRequest2
* LaTex with pdfpages package
* only tested on Linux

To run this:

* look up the OSM relation ID for your citys admin boundary polygon
* edit the header part of the `wanderatlas.php` file
* run `php wanderatlas.php`
* wait ... this will roughly need one minute per route if the server is otherwise idle
* find the result in `my-atlas.pdf`

