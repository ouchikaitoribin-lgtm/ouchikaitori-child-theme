<?php
/**
 * Shared data for the visit-area landing pages.
 * Add a record here when a new service area is opened, then create a WordPress
 * page using the shared "おうち買取便 地域別出張買取" template.
 */
return array(
	'kyoto-city' => array(
		'prefecture'      => '京都府',
		'city'            => '京都市',
		'area_unit'       => '区',
		'coverage_label'  => '全11区',
		'areas'           => array( '北区', '上京区', '左京区', '中京区', '東山区', '下京区', '南区', '右京区', '伏見区', '山科区', '西京区' ),
		'address_prefix'  => '京都市',
		'map_center'      => array( 'lat' => 35.0116, 'lng' => 135.7681 ),
		'preview_address' => array(
			'postal_code' => '604-8571',
			'street'      => '寺町通御池上る上本能寺前町488',
			'building'    => 'テスト用',
		),
	),
);
