<?php
namespace abrain\Einsatzverwaltung;

use WP_Post;
use WP_UnitTestCase;

/**
 * Class DataTest
 * @package abrain\Einsatzverwaltung
 */
class DataTest extends WP_UnitTestCase
{
    public function testGetJahreMitEinsatz()
    {
        $this->assertEquals(array(), Data::getJahreMitEinsatz());

        $reportFactory = new ReportFactory();
        $reportFactory->generateManyForYear('2014', 2);
        $reportFactory->generateManyForYear('2015', 2);
        $reportFactory->generateManyForYear('2017', 2);
        $this->assertEqualSets(array(2014, 2015, 2017), Data::getJahreMitEinsatz());
    }

    public function testGetEinsatzberichte()
    {
        $reportFactory = new ReportFactory();
        $reportIds2014 = $reportFactory->generateManyForYear('2014', 3);
        $reportIds2015 = $reportFactory->generateManyForYear('2015', 4);
        $reportFactory->generateManyForYear('2017', 2);

        // check a certain year
        $reports = Data::getEinsatzberichte(2015);
        $this->assertCount(4, $reports);
        $reportIds = array_map(function (WP_Post $report) {
            return $report->ID;
        }, $reports);
        $this->assertEqualSets($reportIds2015, $reportIds);

        // check a different year
        $reports = Data::getEinsatzberichte(2014);
        $this->assertCount(3, $reports);
        $reportIds = array_map(function (WP_Post $report) {
            return $report->ID;
        }, $reports);
        $this->assertEqualSets($reportIds2014, $reportIds);
    }
}