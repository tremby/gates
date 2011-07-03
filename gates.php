<?php

//TODO: wind speed and direction and gust speed
//TODO: roads
//TODO: photos
ini_set("display_errors", true);

define("ENDPOINT_LINKEDGEODATA", "http://linkedgeodata.org/sparql/");

// include the ARC2 libraries
require_once "arc2/ARC2.php";
require_once "Graphite/graphite/Graphite.php";

$ns = array(
	"geonames" => "http://www.geonames.org/ontology#",
	"geo" => "http://www.w3.org/2003/01/geo/wgs84_pos#",
	"foaf" => "http://xmlns.com/foaf/0.1/",
	"om" => "http://www.opengis.net/om/1.0/",
	"om2" => "http://rdf.channelcoast.org/ontology/om_tmp.owl#",
	"gml" => "http://www.opengis.net/gml#",
	"xsi" => "http://schemas.opengis.net/om/1.0.0/om.xsd#",
	"rdf" => "http://www.w3.org/1999/02/22-rdf-syntax-ns#",
	"rdfs" => "http://www.w3.org/2000/01/rdf-schema#",
	"owl" => "http://www.w3.org/2002/07/owl#",
	"pv" => "http://purl.org/net/provenance/ns#",
	"xsd" => "http://www.w3.org/2001/XMLSchema#",
	"dc" => "http://purl.org/dc/elements/1.1/",
	"lgdo" => "http://linkedgeodata.org/ontology/",
	"georss" => "http://www.georss.org/georss/",
	"eurostat" => "http://www4.wiwiss.fu-berlin.de/eurostat/resource/eurostat/",
	"postcode" => "http://data.ordnancesurvey.co.uk/ontology/postcode/",
	"admingeo" => "http://data.ordnancesurvey.co.uk/ontology/admingeo/",
	"skos" => "http://www.w3.org/2004/02/skos/core#",
	"dbpedia-owl" => "http://dbpedia.org/ontology/",
	"ssn" => "http://purl.oclc.org/NET/ssnx/ssn#",
	"ssne" => "http://www.semsorgrid4env.eu/ontologies/SsnExtension.owl#",
	"DUL" => "http://www.loa-cnr.it/ontologies/DUL.owl#",
	"time" => "http://www.w3.org/2006/time#",
	"sw" => "http://sweet.jpl.nasa.gov/2.1/sweetAll.owl#",
	"id-semsorgrid" => "http://id.semsorgrid.ecs.soton.ac.uk/",
);

// load tide sensor linked data
$tideobservationsURI = "http://id.semsorgrid.ecs.soton.ac.uk/observations/cco/lymington_tide/TideHeight/latest";
$tideobservationsURI = "http://id.semsorgrid.ecs.soton.ac.uk/observations/cco/lymington_tide/TideHeight/20110101"; //TODO: delete this line

$graph = new Graphite($ns);
$graph->cacheDir("/tmp/mashupcache/graphite");
$triples = $graph->load($tideobservationsURI);
if ($triples < 1)
	die("failed to load any triples from '$tideobservationsURI'");

// get tide sensor URI
$sensor = $graph->allOfType("ssn:Observation")->get("ssn:observedBy")->distinct()->current();
if ($sensor->isNull())
	die("no results yet today");

// collect times and heights
$tideobservations = array();
foreach ($graph->allOfType("ssn:Observation") as $observationNode) {
	if ($observationNode->get("ssn:observedProperty") != "http://www.semsorgrid4env.eu/ontologies/CoastalDefences.owl#TideHeight")
		continue;
	$timeNode = $observationNode->get("ssn:observationResultTime");
	if (!$timeNode->isType("time:Interval"))
		continue;
	$tideobservations[] = array(strtotime($timeNode->get("time:hasBeginning")),
		floatVal((string) $observationNode->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityValue")));
}

// sort in ascending date order
usort($tideobservations, "sortreadings");

// load wave height sensor linked data
$waveobservationsURI = "http://id.semsorgrid.ecs.soton.ac.uk/observations/cco/haylingisland/Hs/latest";
$waveobservationsURI = "http://id.semsorgrid.ecs.soton.ac.uk/observations/cco/haylingisland/Hs/20110101"; //TODO: delete this line

$graph = new Graphite();
foreach ($ns as $short => $long)
	$graph->ns($short, $long);
$triples = $graph->load($waveobservationsURI);
if ($triples < 1)
	die("failed to load any triples from '$waveobservationsURI'");

// get tide sensor URI
$sensor = $graph->allOfType("ssn:Observation")->get("ssn:observedBy")->distinct()->current();
if ($sensor->isNull())
	die("no results yet today");

// collect times and heights
$waveobservations = array();
foreach ($graph->allOfType("ssn:Observation") as $observationNode) {
	if ($observationNode->get("ssn:observedProperty") != "http://marinemetadata.org/2005/08/ndbc_waves#Wind_Wave_Height")
		continue;
	$timeNode = $observationNode->get("ssn:observationResultTime");
	if (!$timeNode->isType("time:Interval"))
		continue;
	$waveobservations[] = array(strtotime($timeNode->get("time:hasBeginning")),
		floatVal((string) $observationNode->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityValue")));
}

// sort in ascending date order
usort($waveobservations, "sortreadings");

// current time
$now = strtotime("2011-02-15 11:45"); //TODO: replace this with current time

// get predicted tide height
$predicted = array();
$offset = null;//TODO: remove!
foreach (file("http://apps.semsorgrid.ecs.soton.ac.uk/tide/") as $line) {
	list($time, $height) = explode("\t", $line);
	if (is_null($offset))
		$offset = $time - strtotime("2011-02-15");//TODO: remove!
	$predicted[] = array(($time - $offset), trim($height));//TODO: remove offset!
}

// get current tide trend and predicted tide trend
$tmp = array_slice($tideobservations, -2);
$rising_observed = $tmp[0][1] < $tmp[1][1];
foreach ($predicted as $i => $reading)
	if ($reading[0] > $now)
		break;
if ($i == 0) {
	$r1 = $predicted[0];
	$r2 = $predicted[1];
} else {
	$r1 = $predicted[$i - 1];
	$r2 = $predicted[$i];
}
$rising_predicted = $r1[1] < $r2[1];

// next minimum or maximum
$nextminormax = null;
foreach ($predicted as $reading) {
	if ($reading[0] < $now)
		continue;
	if (is_null($nextminormax)) {
		$nextminormax = $reading;
		continue;
	}
	if ($rising_predicted && $reading[1] > $nextminormax[1] || !$rising_predicted && $reading[1] < $nextminormax[1])
		$nextminormax = $reading;
	else
		break;
}

// array of flood gates
$gates = array(
	array(
		"name" => "High Street",
		"lat" => 50.7908,
		"lon" => -1.1026,
		"threshold" => 2.7,
	),
	array(
		"name" => "Queen Street",
		"lat" => 50.7997,
		"lon" => -1.1046,
		"threshold" => 3.2,
	),
	array(
		"name" => "Circular Road",
		"lat" => 50.8078,
		"lon" => -1.0918,
		"threshold" => 3.9,
	),
	array(
		"name" => "Mile End Road",
		"lat" => 50.8087,
		"lon" => -1.0873,
		"threshold" => 4.2,
	),
	array(
		"name" => "Wharf Road",
		"lat" => 50.8131,
		"lon" => -1.0869,
		"threshold" => 4.2,
	),
	array(
		"name" => "Twyford Avenue",
		"lat" => 50.8222,
		"lon" => -1.0848,
		"threshold" => 5.0,
	),
);

// order by threshold
function sortbythreshold($a, $b) {
	$diff = $a["threshold"] - $b["threshold"];
	if ($diff > 0)
		return 1;
	if ($diff < 0)
		return -1;
	return 0;
}
uasort($gates, "sortbythreshold");

// lowest threshold for "warning" area on chart
$minthreshold = (float) INF;
foreach ($gates as $gate)
	$minthreshold = min($minthreshold, $gate["threshold"]);

// current height
$currentheight = $tideobservations[count($tideobservations) - 1][1];

// gates which should be closed already
$gatesshouldbeclosed = array();
foreach ($gates as $index => $gate)
	if ($gate["threshold"] <= $currentheight)
		$gatesshouldbeclosed[$index] = $gate;

// next gates to close, if the sea level rises
$nextgatestoclose = array();
$tmp = (float) INF;
foreach ($gates as $gate)
	if ($gate["threshold"] > $currentheight)
		$tmp = min($tmp, $gate["threshold"]);
foreach ($gates as $index => $gate)
	if ($gate["threshold"] == $tmp)
		$nextgatestoclose[$index] = $gate;

?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title>Flood gates</title>
	<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
	<script type="text/javascript" src="flot/jquery.flot.js"></script>
	<script type="text/javascript" src="flot/jquery.flot.stack.js"></script>
	<style type="text/css">
		html, body {
			background-color: #346;
			font-size: 14px;
		}
		.shadow {
			-moz-box-shadow: 3px 3px 15px -5px #000;
		}
		.hint {
			color: #666;
			font-size: 80%;
			font-style: italic;
		}
		ul {
			padding: 0 0 0 1.5em;
		}
		table {
			text-align: left;
			margin: 0 auto;
			width: 100%;
			font-size: 100%;
			line-height: inherit;
		}
		table.spaced td {
			padding: 0.5em;
		}
		td {
			padding: 1px;
			vertical-align: top;
		}
		#wrapper {
			margin: 2em;
			border: 3px solid #123;
			-moz-border-radius: 5px;
			font-family: "Trebuchet MS";
			padding: 1.5em;
			background-color: #ccd;
			-moz-box-shadow: 5px 5px 10px #222;
			vertical-align: top;
		}
		a.uri {
			padding-right: 20px;
			background-image: url(images/uri.png);
			background-repeat: no-repeat;
			background-position: center right;
		}
		h2 {
			font-size: 120%;
			font-weight: bold;
			font-style: normal;
			margin: 1em 0 0.5em;
		}
		h3 {
			font-size: 100%;
			font-weight: bold;
			color: #667;
			text-shadow: 1px 1px 0px #fff;
			text-align: center;
		}
		table.modules {
			border-collapse: separate;
			border-spacing: 1em;
		}
		.box {
			margin: 1em;
		}
		.box, table.modules > tbody > tr > td {
			text-align: center;
			border: 2px solid #9ab;
			-moz-border-radius: 5px;
			background-color: #dde;
			padding: 0.5em;
			-moz-box-shadow: inset 5px 5px 10px -4px #fff, 3px 3px 15px -8px #000;
		}
		.box > p, table.modules > tbody > tr > td > p {
			text-shadow: 1px 1px 0px #fff;
			color: #889;
		}
		.box dl, .box ol, .box ul, table.modules dl, table.modules ol, table.modules ul {
			text-align: left;
		}
		.box h2, table.modules td h2 {
			margin-top: 0;
		}
		.clearer {
			clear: both;
		}
		p.big {
			font-size: 150%;
		}
		p.big strong {
			color: #444;
		}
		h3 strong {
			color: #222;
		}
		.right {
			float: right;
			margin: 0 0 0.5em 0.5em;
		}
		.left {
			float: left;
			margin: 0 0.5em 0.5em 0;
		}
		.closed, .rising {
			color: red !important;
		}
		.open, .falling {
			color: green !important;
		}
		.pendingclosing {
			color: #fe0 !important;
			text-shadow: 1px 1px 0px #440;
		}
	</style>
	<script type="text/javascript">
		/*
		$(document).ready(function() {
			// slidey definition lists
			expandcollapsedl = function(e) {
				e.preventDefault();
				if ($(this).parents("dt:first").next("dd:first").is(":visible")) {
					$(this).removeClass("collapselink").addClass("expandlink");
					$(this).parents("dt:first").next("dd:first").slideUp("fast");
				} else {
					$(this).parents("dl:first").find(".collapselink").removeClass("collapselink").addClass("expandlink");
					$(this).removeClass("expandlink").addClass("collapselink");
					$(this).parents("dl:first").children("dd").not($(this).parents("dt:first").next("dd:first")).slideUp("fast");
					$(this).parents("dt:first").next("dd:first").slideDown("fast");
				}
			};
			$("dl.single > dd").hide();
			$("dl.single > dt").prepend("<a class=\"expandlink\" href=\"#\"></a>");
			$("dl.single > dt a.expandlink, dl.single > dt a.collapselink").click(expandcollapsedl);
		});
		*/
	</script>
</head>
<body>
<div id="wrapper">

<h1>Flood gates</h1>

<div class="box">
	<h2>Current tide height</h2>
	<p>Last measured <?php echo friendlydate_html($tideobservations[count($tideobservations) - 1][0]); ?>, the tide height is <strong><?php echo $currentheight; ?>m</strong>.</p>
	<div id="tideheight" style="width: 760px; height:300px; margin: 0 auto;"></div>
	<div id="tideheight_legend"></div>
	<script type="text/javascript">
		$(function() {
			<?php
			function timestamptomilliseconds($readings) {
				$a = array();
				foreach ($readings as $reading)
					$a[] = array($reading[0] * 1000, $reading[1]);
				return $a;
			}
			echo "var tide_observed = " . json_encode(timestamptomilliseconds($tideobservations)) . ";";
			echo "var wave_observed = " . json_encode(timestamptomilliseconds($waveobservations)) . ";";
			echo "var tide_predicted = " . json_encode(timestamptomilliseconds($predicted)) . ";";
			?>
			$.plot($("#tideheight"), [
				{
					stack: true,
					label: "Observed tide height",
					data: tide_observed,
					color: "#33f"
				},
				{
					stack: true,
					lines: { fill: true, fillColor: "rgba(30, 200, 255, 0.3)" },
					label: "Observed wave height plus observed tide height",
					data: wave_observed,
					color: "#091"
				},
				{
					label: "Predicted tide height",
					data: tide_predicted,
					color: "#ff0"
				}
			], {
				xaxis: { mode: "time" },
				grid: {
					markings: [
						{ color: "#fcc", yaxis: { from: <?php echo $minthreshold; ?> } },
						{ color: "#060", lineWidth: 1, xaxis: { from: <?php echo $now * 1000; ?>, to: <?php echo $now * 1000; ?> } }
						<?php foreach ($gates as $gate) echo ", { color: \"#f66\", lineWidth: 1, yaxis: { from: $gate[threshold], to: $gate[threshold] } }"; ?>
					],
					backgroundColor: "#fff"
				},
				legend: {
					show: true,
					position: "ne",
					container: $("#tideheight_legend"),
					noColumns: 3
				}
			});
		});
	</script>
</div>

<div class="box">
	<p class="big">Based on current observations, tide level is <strong class="<?php echo $rising_observed ? "rising" : "falling"; ?>"><?php echo $rising_observed ? "rising" : "falling"; ?></strong></p>
	<p class="big">The BBC predicts the tide level will <strong class="<?php echo $rising_predicted ? "rising" : "falling"; ?>"><?php echo $rising_predicted ? "rise" : "fall"; ?></strong> to a level of <strong><?php echo $nextminormax[1]; ?>m <?php echo friendlydate_html($nextminormax[0]); ?></strong></p>
</div>

<?php
$modules = array();
?>

<div class="box">
	<h2>All gates</h2>

	<?php
	$markers = array();
	foreach ($gates as $index => $gate)
		$markers[] = "markers=color:" . gatecolour($gate) . "%7Clabel:" . $index . "%7C$gate[lat],$gate[lon]";
	?>
	<img class="shadow right" src="http://maps.google.com/maps/api/staticmap?size=550x300&amp;maptype=roadmap&amp;<?php echo implode("&amp;", $markers); ?>&amp;sensor=false">

	<ul>
		<?php foreach ($gates as $index => $gate) { ?>
			<li><span class="<?php echo gatestatus($gate); ?>">Gate <?php echo $index; ?>: <strong><?php echo htmlspecialchars($gate["name"]); ?></strong></span></li>
		<?php } ?>
	</ul>

	<div class="clearer"></div>
</div>

<div class="box">
	<h2>Summary</h2>
	<?php if (count($gatesshouldbeclosed)) { ?>
		<h3>Currently <?php echo count($gatesshouldbeclosed); ?> gates should be <span class="closed">closed</span>:</h3>
		<ul>
			<?php foreach ($gatesshouldbeclosed as $index => $gate) { ?>
			<li><span class="closed">Gate <?php echo $index; ?>: <strong><?php echo htmlspecialchars($gate["name"]); ?></strong></span> (threshold: <?php echo $gate["threshold"]; ?>m)<?php //TODO: icons of notifiables? ?></li>
			<?php } ?>
		</ul>
	<?php } else { ?>
		<h3>Currently no gates need to be closed</h3>
	<?php } ?>
	<?php
	$nextthresholdgate = array_pop(array_values($nextgatestoclose));
	if ($rising_predicted && $nextminormax[1] >= $nextthresholdgate["threshold"]) {
		foreach ($predicted as $reading) {
			if ($reading[0] < $now)
				continue;
			if ($reading[1] >= $nextthresholdgate["threshold"]) {
				$predicted_text = "predicted to occur <strong>" . friendlydate_html($reading[0]) . "</strong>";
				break;
			}
		}
	} else
		$predicted_text = "not predicted";
	?>
	<h3>If the tide rises as far as <?php echo $nextthresholdgate["threshold"]; ?>m (<?php echo $predicted_text; ?>) the next gates to be closed are:</h3>
	<ul>
		<?php foreach ($nextgatestoclose as $index => $gate) { ?>
			<li><span class="pendingclosing">Gate: <?php echo $index; ?>: <strong><?php echo htmlspecialchars($gate["name"]); ?></strong></span><?php //TODO: icons ?></li>
		<?php } ?>
	</ul>
	<div class="clearer"></div>
</div>

<?php foreach ($gates as $index => $gate) { ob_start(); ?>
	<?php
	$amenities = nearbyamenities(array(
		"lgdo:Park",
		"lgdo:CaravanSite",
		"lgdo:Shelter",
		"lgdo:Hospital",
		"lgdo:Beach",
		"lgdo:FirstAid",
		"lgdo:Pier",
		"lgdo:RecreationGround",
		"lgdo:School",
		"lgdo:Police",
		"lgdo:FireStation",
		"lgdo:College",
		"lgdo:Playground",
		"lgdo:Nursery",
		"lgdo:NaturalBeach",
		"lgdo:HealthCentre",
		"lgdo:RailwayStation",
		"lgdo:CampSite",
		"lgdo:CaveEntrance",
		"lgdo:Airport",
		"lgdo:Cave",
		"lgdo:Clinic",
		"lgdo:RedCross",
		"lgdo:Rescue",
		"lgdo:MedicalCentre",
		"lgdo:BuildingHospital",
		"lgdo:RetirementHome",
		"lgdo:Mineshaft",
		"lgdo:ChildCare",
		"lgdo:TourismBeach",
		"lgdo:FuneralHome",
		"lgdo:Campsite",
		"lgdo:Mine",
		"lgdo:Doctor",
	), array($gate["lat"], $gate["lon"]), 0.5);
	$markers = "&amp;markers=color:" . (array_key_exists($index, $nextgatestoclose) ? "yellow" : ($gate["threshold"] <= $currentheight ? "red" : "green")) . "%7Clabel:$index%7C$gate[lat],$gate[lon]";
	if (count($amenities))
		$markers .= "&amp;markers=size:small%7Ccolor:blue";
	foreach ($amenities as $amenity)
		$markers .= "%7C" . $amenity[1][0] . "," . $amenity[1][1];
	?>
	<h3>Gate <?php echo $index; ?>: <?php echo htmlspecialchars($gate["name"]); ?></h3>
	<img class="shadow" src="http://maps.google.com/maps/api/staticmap?size=350x200&amp;maptype=roadmap<?php echo $markers; ?>&amp;sensor=false">
	<ul>
		<li>Coordinates: <?php echo $gate["lat"]; ?>, <?php echo $gate["lon"]; ?></li>
		<li>Threshold: <?php echo $gate["threshold"]; ?>m</li>
		<li>
			<?php if (array_key_exists($index, $nextgatestoclose)) { ?>
				Should be <strong class="open">open</strong>, but is the <strong class="pendingclosing">next to close</strong> should the tide level rise (<?php echo $predicted_text; ?>)
			<?php } else if ($currentheight < $gate["threshold"]) { ?>
				Should be <strong class="open">open</strong>
			<?php } else { ?>
				Must be <strong class="closed">closed</strong>
			<?php } ?>
		</li>
		<li>Notifiable amenities:
			<?php if (count($amenities)) { ?>
				<ul>
					<?php foreach ($amenities as $amenity) { ?>
						<li><?php echo htmlspecialchars($amenity[0]); ?> (<?php echo sprintf("%.02f", distance($amenity[1], array($gate["lat"], $gate["lon"]))); ?>km from gate)</li>
					<?php } ?>
				</ul>
			<?php } else { ?>
				nothing within 0.5km
			<?php } ?>
		</li>
	</ul>
<?php $modules[] = ob_get_clean(); } ?>

<table class="modules" width="100%">
	<tbody>
		<?php while (count($modules)) {
			$module1 = array_shift($modules);
			$module2 = array_shift($modules);
			?>
			<tr>
				<td><?php echo $module1; ?></td>
				<td><?php echo is_null($module2) ? "" : $module2; ?></td>
			</tr>
		<?php } ?>
	</tbody>
</table>

</div>
</body>
</html>

<?php

// return a readable date in HTML form
function friendlydate_html($timestamp, $html = true) {
	$now = time();
	$now = $GLOBALS["now"]; //TODO: remove this line once we're using the actual time
	$diff = $now - $timestamp;
	if ($timestamp == $now)
		$datestring = "right now";
	else if ($timestamp > strtotime("+1 day", $now) || $timestamp < strtotime("January 1 00:00", $now))
		// more than 24 hours ahead or not this year -- give full date
		$datestring = date("Y M j, H:i", $timestamp);
	else if ($diff < 0) {
		// future
		if ($diff < -60 * 60) {
			// more than an hour in the future -- give rough number of hours
			$hours = round(-$diff / 60 / 60);
			$datestring = "in $hours hour" . plural($hours);
		} else if ($diff < -60) {
			// more than a minute in the future -- give rough number of minutes
			$minutes = round(-$diff / 60);
			$datestring = "in $minutes minute" . plural($minutes);
		} else
			$datestring = "imminently";
	} else if ($timestamp < strtotime("today", $now)) {
		// yesterday or before
		$datestring = date("D, M j, H:i", $timestamp);
		if ($timestamp < strtotime("-6 days 00:00", $now))
			// a week or more ago -- leave at month and day
			true;
		else if ($timestamp < strtotime("-1 day 00:00", $now))
			// before yesterday -- additionally give number of days ago
			$datestring .= " (" . round((strtotime("00:00", $now) - strtotime("00:00", $timestamp)) /24/60/60) . "&nbsp;days&nbsp;ago)";
		else
			// yesterday -- say so
			$datestring .= " (yesterday)";
	} else if ($diff > 60 * 60) {
		// more than an hour ago -- give rough number of hours
		$hours = round($diff / 60 / 60);
		$datestring = $hours . " hour" . plural($hours) . " ago";
	} else if ($diff > 60) {
		// more than a minute ago -- give rough number of minutes
		$minutes = round($diff / 60);
		$datestring = $minutes . " minute" . plural($minutes) . " ago";
	} else
		$datestring = "just now";
	if ($html)
		return "<span class=\"date\" title=\"" . date("Y-m-d H:i:s T (O)", $timestamp) . "\">$datestring</span>";
	return str_replace("&nbsp;", " ", $datestring);
}
// plain text
function friendlydate($timestamp) {
	return friendlydate_html($timestamp, false);
}

function plural($input, $pluralsuffix = "s", $singularsuffix = "") {
	if (is_array($input) && count($input) != 1 || is_numeric($input) && $input != 1)
		return $pluralsuffix;
	return $singularsuffix;
}

// return a Sparql PREFIX string, given a namespace key from the global $ns 
// array, or many such PREFIX strings for an array of such keys
function prefix($n = null) {
	global $ns;
	if (is_null($n))
		$n = array_keys($ns);
	if (!is_array($n))
		$n = array($n);
	$ret = "";
	foreach ($n as $s)
		$ret .= "PREFIX $s: <" . $ns[$s] . ">\n";
	return $ret;
}

// return results of a Sparql query
// maxage is the number of seconds old an acceptable cached result can be 
// (default one day, 0 means it must be collected newly. false means must be 
// collected newly and the result will not be stored. true means use cached 
// result however old it is)
// type is passed straight through to Arc
// if no PREFIX lines are found in the query all known prefixes are prepended
function sparqlquery($endpoint, $query, $type = "rows", $maxage = 86400/*1 day*/) {
	$cachedir = "/tmp/mashupcache/sparql" . md5($endpoint);

	if (!is_dir($cachedir))
		mkdir($cachedir) or die("couldn't make cache directory");

	if (strpos($query, "PREFIX") === false)
		$query = prefix() . $query;

	$cachefile = $cachedir . "/" . md5($query . $type);

	// collect from cache if available and recent enough
	if ($maxage === true && file_exists($cachefile) || $maxage !== false && $maxage > 0 && file_exists($cachefile) && time() < filemtime($cachefile) + $maxage)
		return unserialize(file_get_contents($cachefile));

	// cache is not to be used or cached file is out of date. query endpoint
	$config = array(
		"remote_store_endpoint" => $endpoint,
		"reader_timeout" => 120,
		"ns" => $GLOBALS["ns"],
	);
	$store = ARC2::getRemoteStore($config);
	$result = $store->query($query, $type);
	if (!empty($store->errors)) {
		foreach ($store->errors as $error)
			trigger_error("Sparql error: " . $error, E_USER_WARNING);
		return null;
	}

	// store result unless caching is switched off
	if ($maxage !== false)
		file_put_contents($cachefile, serialize($result));

	return $result;
}

// query linkedgeodata.org for nearby amenities
function nearbyamenities($type, $latlon, $radius = 10) {
	global $ns;

	// upgrade $type to an array of itself if an array wasn't given
	if (!is_array($type))
		$type = array($type);

	// execute query
	$rows = sparqlquery(ENDPOINT_LINKEDGEODATA, "
		SELECT *
		WHERE {
			{ ?place a " . implode(" . } UNION { ?place a ", $type) . " . }
			?place
				a ?type ;
				geo:geometry ?placegeo ;
				rdfs:label ?placename .
			FILTER(<bif:st_intersects> (?placegeo, <bif:st_point> ($latlon[1], $latlon[0]), $radius)) .
		}
	");

	// collect results
	$results = array();
	foreach ($rows as $row) {
		$coords = parsepointstring($row['placegeo']);
		$results[$row["place"]] = array($row['placename'], $coords, distance($coords, $latlon));
	}

	// sort according to ascending distance from centre
	usort($results, "sortbythirdelement");

	return $results;
}
function sortbythirdelement($a, $b) {
	$diff = $a[2] - $b[2];
	// usort needs integers, floats aren't good enough
	return $diff < 0 ? -1 : ($diff > 0 ? 1 : 0);
}

// parse a string
// 	POINT(longitude latitude)
// and return
// 	array(float latitude, float longitude)
function parsepointstring($string) {
	$coords = array_map("floatVal", explode(" ", preg_replace('%^.*\((.*)\)$%', '\1', $string)));
	return array_reverse($coords);
}

// return the distance in km between two array(lat, lon)
function distance($latlon1, $latlon2) {
	$angle = acos(sin(deg2rad($latlon1[0])) * sin(deg2rad($latlon2[0])) + cos(deg2rad($latlon1[0])) * cos(deg2rad($latlon2[0])) * cos(deg2rad($latlon1[1] - $latlon2[1])));
	$earthradius_km = 6372.8;
	return $earthradius_km * $angle;
}

// get wave height nearest to given time
function nearestwaveheight($timestamp) {
	$bestdiff = (float) INF;
	$bestheight = null;
	foreach ($GLOBALS["waveobservations"] as $reading) {
		$diff = abs($reading[0] - $timestamp);
		if ($diff < $bestdiff) {
			$bestdiff = $diff;
			$bestheight = $reading[1];
		}
	}
	return $bestheight;
}

// get gate status
function gatestatus($gate) {
	global $nextgatestoclose, $currentheight;
	$yellow = false;
	foreach ($nextgatestoclose as $g)
		if ($g["name"] == $gate["name"])
			return "pendingclosing";
	if ($gate["threshold"] <= $currentheight)
		return "closed";
	return "open";
}

// get gate colour
function gatecolour($gate) {
	switch (gatestatus($gate)) {
		case "pendingclosing":
			return "yellow";
		case "closed":
			return "red";
		default:
			return "green";
	}
}

function sortreadings($a, $b) {
	return $a[0] - $b[0];
}

?>
