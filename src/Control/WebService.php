<?php

namespace Smindel\GIS\Control;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use Smindel\GIS\ORM\FieldType\DBGeography;
use proj4php\Proj4php;
use proj4php\Proj;
use proj4php\Point;

class WebService extends Controller
{
    private static $url_handlers = array(
        '$Model' => 'handleAction',
    );

    public function index($request)
    {
        $model = str_replace('-', '\\', $request->param('Model'));
        $formater = 'format_' . $request->getExtension() ?: 'GeoJson';
        if (!Config::inst()->get($model, 'web_service') || !$formater) return $this->httpError(404);
        return $this->$formater($model::get(), $request->requestVars());
    }

    public static function format_geojson($list, $query)
    {
        $collection = [];

        $modelClass = $list->dataClass();

        $geometryField = array_search('Geography', Config::inst()->get($modelClass, 'db'));

        $propertyMap = Config::inst()->get($modelClass, 'web_service');
        if ($propertyMap === true) $propertyMap = singleton($modelClass)->summaryFields();

        if (($epsg = Config::inst()->get(DBGeography::class, 'default_projection')) != 4326) {
            $projDef = Config::inst()->get(DBGeography::class, 'projections')[$epsg];
            $proj4 = new Proj4php();
            $proj4->addDef('EPSG:' . $epsg, $projDef);
            $proj = new Proj('EPSG:' . $epsg, $proj4);
        }

        foreach ($list as $item) {

            if (!$item->canView()) {
                continue;
            }

            if ($item->hasMethod('getWebServiseGeometry')) {
                $geometry = $item->getWebServiseGeometry();
            } else {
                $geometry = $item->$geometryField;
            }

            if ($item->hasMethod('getWebServiseProperties')) {
                $properties = $item->getWebServiseProperties();
            } else {
                $properties = [];
                foreach ($propertyMap as $fieldName => $propertyName) {
                    $properties[$propertyName] = $item->$fieldName;
                }
            }

            $array = DBGeography::to_array($geometry);

            if ($epsg != 4326) {
                $point = new Point($array['coordinates'][1], $array['coordinates'][0], $proj);
                $array['coordinates'] = $proj4->transform(new Proj('EPSG:4326', $proj4), $point)->toArray();
            }

            $collection[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => $array['type'],
                    'coordinates' => $array['coordinates']
                ],
                'properties' => $properties,
            ];
        }

        $raw = [
            'type' => 'FeatureCollection',
            'features' => $collection,
        ];

        return json_encode($raw);
    }
}
