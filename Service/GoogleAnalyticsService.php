<?php

namespace MediaFigaro\GoogleAnalyticsApi\Service;

use Google_Client;
use Google_Service_AnalyticsReporting;
use Google_Service_AnalyticsReporting_DateRange;
use Google_Service_AnalyticsReporting_Dimension;
use Google_Service_AnalyticsReporting_DimensionFilter;
use Google_Service_AnalyticsReporting_DimensionFilterClause;
use Google_Service_AnalyticsReporting_GetReportsRequest;
use Google_Service_AnalyticsReporting_Metric;
use Google_Service_AnalyticsReporting_MetricFilter;
use Google_Service_AnalyticsReporting_MetricFilterClause;
use Google_Service_AnalyticsReporting_OrderBy;
use Google_Service_AnalyticsReporting_ReportRequest;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * Class GoogleAnalyticsService
 * @package MediaFigaro\GoogleAnalyticsApi\Service
 */
class GoogleAnalyticsService
{

    /**
     * @var Google_Client
     */
    private $client;
    /**
     * @var Google_Service_AnalyticsReporting
     */
    private $analytics;
    /**
     * @var Google_Service_AnalyticsReporting_Dimension[]
     */
    private $reportingDimensions = null;
    /**
     * @var Google_Service_AnalyticsReporting_Metric[]
     */
    private $reportingMetrics = null;

    /**
     * construct
     */
    public function __construct($keyFileLocation)
    {

        if (!file_exists($keyFileLocation)) {
            throw new Exception("can't find file key location defined by google_analytics_api.google_analytics_json_key parameter, ex : ../data/analytics/analytics-key.json, defined : " . $keyFileLocation);
        }

        $this->client = new Google_Client();
        $this->client->setApplicationName("GoogleAnalytics");
        $this->client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
        $this->client->setAuthConfig($keyFileLocation);

        $this->analytics = new Google_Service_AnalyticsReporting($this->client);

    }

    /**
     * @return Google_Service_AnalyticsReporting
     */
    public function getAnalytics()
    {

        return $this->analytics;

    }

    /**
     * @return Google_Client
     */
    public function getClient()
    {

        return $this->client;

    }

    /**
     * getDataDateRangeMetricsDimensions
     *
     * simple helper & wrapper of Google Api Client
     *
     * @param $viewId
     * @param array $dateRanges
     * @param array $metrics
     * @param array $dimensions
     * @param array $sorting ( = [ ['fields']=>['sessions','bounceRate',..] , 'order'=>'descending' ] )
     * @param array $filterMetric ( = [ ['metric_name']=>['sessions'] , 'operator'=>'LESS_THAN' , 'comparison_value'=>'100' ] )
     * @param array $filterDimension ( = [ ['dimension_name']=>['sourceMedium'] , 'operator'=>'EXACT' , 'expressions'=>['my_campaign'] ] )
     * @return mixed
     *
     * @link https://developers.google.com/analytics/devguides/reporting/core/dimsmets
     * @link https://ga-dev-tools.appspot.com/query-explorer/
     * @link https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/web-php
     * @link https://developers.google.com/analytics/devguides/reporting/core/v4/samples
     * @link https://github.com/google/google-api-php-client
     *
     */
    public function getDataDateRangeMetricsDimensions($viewId, array $dateRanges, $metrics = 'sessions', $dimensions = null, array $sorting = [], array $filterMetrics = [], array $filterDimensions = [], $pageSize = null)
    {

        $this->reportingMetrics = [];
        $this->reportingDimensions = [];

        if (is_string($dateRanges[0])) {
            //single date range
            $dateRanges[] = $dateRanges;

        }

        $dateRangeObjects = [];
        foreach ($dateRanges as $index => $dateRange) {
            if (is_array($dateRange)) {
                $dateRangeObject = new Google_Service_AnalyticsReporting_DateRange();

                $dateRangeObject->setStartDate($dateRange[0]);
                $dateRangeObject->setEndDate($dateRange[1]);
                $dateRangeObjects[] = $dateRangeObject;
            }
        }


        if (isset($metrics) && !is_array($metrics)) {
            $metrics = [$metrics];
        }

        if (isset($metrics) && is_array($metrics)) {

            $this->reportingDimensions = [];

            foreach ($metrics as $metric) {

                // Create the Metrics object
                $reportingMetrics = new Google_Service_AnalyticsReporting_Metric();
                $reportingMetrics->setExpression("ga:$metric");
                $reportingMetrics->setAlias("$metric");

                $this->reportingMetrics[] = $reportingMetrics;

            }

        }

        if (isset($dimensions) && !is_array($dimensions)) {
            $dimensions = [$dimensions];
        }

        if (isset($dimensions) && is_array($dimensions)) {

            $this->reportingDimensions = [];

            foreach ($dimensions as $dimension) {

                // Create the segment(s) dimension.
                $reportingDimensions = new Google_Service_AnalyticsReporting_Dimension();
                $reportingDimensions->setName("ga:$dimension");

                $this->reportingDimensions[] = $reportingDimensions;

            }
        }

        // Create the ReportRequest object
        $request = new Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($viewId);
        $request->setDateRanges($dateRangeObjects);

        if ($pageSize && is_int($pageSize)) {
            $request->setPageSize($pageSize);
        }

        // add dimensions
        if (isset($this->reportingDimensions) && is_array($this->reportingDimensions)) {
            $request->setDimensions($this->reportingDimensions);
        }

        // add metrics
        if (isset($this->reportingMetrics) && is_array($this->reportingMetrics)) {
            $request->setMetrics($this->reportingMetrics);
        }

        $request = $this->sort($sorting, $request);
        $request = $this->metricFilters($filterMetrics, $request);
        $request = $this->dimensionFilters($filterDimensions, $request);

        $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests([$request]);

        $reports = $this->analytics->reports->batchGet($body);

        return $this->normalizeResult($metrics, $dimensions, $reports);

    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     *
     * https://ga-dev-tools.appspot.com/query-explorer/
     *
     */
    private function getDataDateRange($viewId, $dateStart, $dateEnd, $metric)
    {

        // Create the DateRange object
        $dateRange = new Google_Service_AnalyticsReporting_DateRange();
        $dateRange->setStartDate($dateStart);
        $dateRange->setEndDate($dateEnd);

        // Create the Metrics object
        $sessions = new Google_Service_AnalyticsReporting_Metric();
        $sessions->setExpression("ga:$metric");
        $sessions->setAlias("$metric");

        if (isset($dimensions) && is_array($dimensions)) {

            $this->reportingDimensions = [];

            foreach ($dimensions as $dimension) {

                // Create the segment dimension.
                $reportingDimensions = new Google_Service_AnalyticsReporting_Dimension();
                $reportingDimensions->setName("ga:$dimension");

                $this->reportingDimensions[] = $reportingDimensions;

            }
        }

        // Create the ReportRequest object
        $request = new Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($viewId);
        $request->setDateRanges($dateRange);

        // add dimensions
        if (isset($this->reportingDimensions) && is_array($this->reportingDimensions))
            $request->setDimensions($this->reportingDimensions);

        $request->setMetrics([$sessions]);

        $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests([$request]);

        $report = $this->analytics->reports->batchGet($body);

        $result = $report->getReports()[0]
            ->getData()
            ->getTotals()[0]
            ->getValues()[0];

        return $result;

    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getSessionsDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'sessions');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getBounceRateDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'bounceRate');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getAvgTimeOnPageDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'avgTimeOnPage');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getPageviewsPerSessionDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'pageviewsPerSession');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getPercentNewVisitsDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'percentNewVisits');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getPageViewsDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'pageviews');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getAvgPageLoadTimeDateRange($viewId, $dateStart, $dateEnd)
    {
        return $this->getDataDateRange($viewId, $dateStart, $dateEnd, 'avgPageLoadTime');
    }

    /**
     * @param $metrics
     * @param $dimensions
     * @param $reports
     * @return array
     */
    private function normalizeResult($metrics, $dimensions, $reports): array
    {
        $data = [];
        foreach ($reports->getReports()[0]->getData()->getRows() as $row) {

            // arrays
            $dimensionsArray = $row->getDimensions();
            $metricsValues = [];

            foreach ($row->getMetrics() as $dateRange => $metricValue) {
                $metricsValues[] = $metricValue->getValues();
            }

            $dimensionsKeyValue = [];
            if (isset($dimensionsArray)) {
                foreach ($dimensionsArray as $index => $v) {
                    $dimensionsKeyValue[$dimensions[$index]] = $v;
                }

            }

            $metricsKeyValue = [];

            if (isset($metrics)) {
                foreach ($metrics as $index => $metricName) {
                    foreach ($metricsValues as $dateRangeIndex => $metricsValue) {
                        $metricsKeyValue[$dateRangeIndex][$metricName] = $metricsValues[$dateRangeIndex][$index];

                    }
                }
            }

            $data[] = [
                'metrics' => $metricsKeyValue,
                'dimensions' => $dimensionsKeyValue
            ];

        }


        return $data;
    }

    /**
     * @param $filterMetric
     * @param $request
     */
    private function metricFilters(array $filterMetrics, Google_Service_AnalyticsReporting_ReportRequest $request): Google_Service_AnalyticsReporting_ReportRequest
    {
        if(empty($filterMetrics)) {
            return $request;

        }

        $metricFilterClauses = [];

        foreach ($filterMetrics as $filterMetric) {
            if (!(isset($filterMetric['metric_name']) && isset($filterMetric['operator']) && isset($filterMetric['comparison_value']))) {
                continue;
            }

            // Create the DimensionFilter.
            $metricFilter = new Google_Service_AnalyticsReporting_MetricFilter();
            $metricFilter->setMetricName('ga:' . $filterMetric['metric_name']);
            $metricFilter->setOperator($filterMetric['operator']);
            $metricFilter->setComparisonValue($filterMetric['comparison_value']);

            // Create the DimensionFilterClauses
            $metricFilterClause = new Google_Service_AnalyticsReporting_MetricFilterClause();
            $metricFilterClause->setFilters([$metricFilter]);

        }

        // add to request
        $request->setMetricFilterClauses($metricFilterClauses);

        return $request;
    }

    /**
     * @param $filterDimensions
     * @param $request
     */
    private function dimensionFilters(array $filterDimensions, Google_Service_AnalyticsReporting_ReportRequest $request): Google_Service_AnalyticsReporting_ReportRequest
    {
        if(empty($filterDimensions)) {
            return $request;

        }

        $dimensionFilterClauses = [];
        foreach ($filterDimensions as $filterDimension) {
            if (!(isset($filterDimension['dimension_name']) && isset($filterDimension['operator']) && isset($filterDimension['expressions']))) {
                continue;
            }


            if (!is_array($filterDimension['expressions'])) {
                $filterDimension['expressions'] = [$filterDimension['expressions']];
            }

            // Create the DimensionFilter.
            $dimensionFilter = new Google_Service_AnalyticsReporting_DimensionFilter();
            $dimensionFilter->setDimensionName('ga:' . $filterDimension['dimension_name']);
            $dimensionFilter->setOperator($filterDimension['operator']);
            $dimensionFilter->setExpressions($filterDimension['expressions']);

            // Create the DimensionFilterClauses
            $dimensionFilterClause = new Google_Service_AnalyticsReporting_DimensionFilterClause();
            $dimensionFilterClause->setFilters(array($dimensionFilter));

            $dimensionFilterClauses[] = $dimensionFilterClause;
        }

        // add to request
        $request->setDimensionFilterClauses($dimensionFilterClauses);

        return $request;
    }

    /**
     * @param $sorting
     * @param $request
     */
    private function sort(array $sorting, Google_Service_AnalyticsReporting_ReportRequest $request): Google_Service_AnalyticsReporting_ReportRequest
    {
        if (!empty($sorting)) {
            $orderBy = new Google_Service_AnalyticsReporting_OrderBy();

            if (isset($sorting['fields']) && is_array($sorting['fields'])) {
                $fields = $sorting['fields'];
                foreach ($fields as $sortingFieldName) {
                    $orderBy->setFieldName("ga:$sortingFieldName");
                }

                if (isset($sorting['order'])) {
                    $order = $sorting['order'];
                    $orderBy->setSortOrder($order);
                }

            }

            $request->setOrderBys($orderBy);

        }

        return $request;
    }

}
