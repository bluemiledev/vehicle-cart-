<?php

namespace App\Http\Controllers;
use Session;
use Illuminate\Http\Request;
use DB; 
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Part\TextPart;
use Illuminate\Support\Facades\Validator;
use App\Traits\ProjectHelpersTrait;

class WebServices extends Controller{
    function __construct() {
        header('Content-Type: application/json; charset=utf-8');
        // CORS Headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        // header("Access-Control-Allow-Headers: Content-Type, Authorization");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        error_reporting(0);
    }
    function show_error($message) {
        echo json_encode(array("status" => "0","message"=>$message,"data" => array()));
        die();
    }
    
    function show_data($message,$data){
        echo json_encode(array("status" => "1","message"=>$message,"data" => $data));
        die();
    }
    function get_vehicles(Request $request){
        $query                      =   DB::table("vehicles");
        if($request->search){
            $query->whereRaw("rego like '%" . trim($request->search) . "%' ");
        }
        if($request->status){
            $query->whereRaw("status like '" . trim($request->status) . "' ");
        }
        $vehicles                 =   $query->where("status","Active")->orderby("id","desc")->get();
        foreach($vehicles as $vehicle) { 
            $data[]     =   array(
                "id"                        =>  $vehicle->id,
                "rego"                      =>  $vehicle->rego ."(" . $vehicle->fleet_no . ")",
            );
        }
        $this->show_data("Vehicles",$data);
    }
    function get_dates_by_vehicles_id(Request $request){
        header('Content-Type: application/json; charset=utf-8');
        // CORS Headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        // header("Access-Control-Allow-Headers: Content-Type, Authorization");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        error_reporting(0);
        if($request->vehicles_id){
            $device_details =   DB::table("devices")->where("vehicles_id",$request->vehicles_id)->first();
            if($device_details->id > 0) { 
                $results = DB::table("csv_data1")
                    ->distinct()
                    ->select("date as d")
                    ->where("device_id", $device_details->serial_no)
                    ->where("actual_min", ">", 0)
                    ->where("actual_max", ">", 0)
                    ->where("actual_avg", ">", 0)
                    ->where("actual_value", 1)
                    ->orderBy("date", "desc")
                    ->get();
                
                $data = $results->pluck('d')->toArray();
                
                $this->show_data("Dates", $data);
            }
            else {
                $this->show_error("Vehicle or device id not matched");        
            }
        }
        $this->show_error("Vehicle id compulsory");
    }
    
    function get_readings(Request $request){
        if($request->vehicles_id && $request->date ){
            $device_details =   DB::table("devices")->where("vehicles_id",$request->vehicles_id)->first();
            if($device_details->id > 0) { 
                $query          =   DB::table("csv_data1");
                $query->whereRaw("(actual_min is not null and actual_max is not null and actual_avg is not null and actual_value is not null)");
                $query->where("device_id",$device_details->serial_no);
                $query->whereDate('date', date("Y-m-d",strtotime($request->date)));
                $query->whereBetween('timings_id', [1, 1400]);
                
                $response_datas     =   $query->orderBy("id")->get();
                
                $device_locations   =   DB::table("devices_locations")
                    ->where("devices_id", $device->id)
                    ->get()
                    ->keyBy(function($item) {
                    return date("Y-m-d H:i", strtotime($item->date_time));
                });
                
                foreach($response_datas as $response_data) { 
                    $date_time_key  =   date("Y-m-d H:i", strtotime($response_data->date . ' ' . $response_data->time));
                    $location       =   $device_locations[$date_time_key] ?? null;
                    if($response_data->chartType == "Digital"){
                        $value          =   $response_data->inverse == "Yes"?($response_data->actual_value == 1?"0":"1"):$response_data->actual_value; 
                        $tt             =   $response_data->chartName . " (D" . DB::table("manual_readings")->where("id",$response_data->manual_readings_id)->first()->number . ")";
                        if($response_data->units != ""){
                           $tt          .=   " " . $response_data->units;
                        }
                        $data[]         =   array(
                            "chartName"         =>  $tt,
                            "date"              =>  date("Y-m-d", strtotime($response_data->date)) ,
                            "time"              =>  date("H:i:00", strtotime($response_data->time)),
                            "date_time"         =>  date("Y-m-d", strtotime($response_data->date)). " " .date("H:i:00", strtotime($response_data->time)),
                            "value"             =>  (int)$value,   
                            "chartType"         =>  $response_data->chartType,
                            "latitude"          =>  $location->latitude ?? "",
                            "longitude"         =>  $location->longitude ?? "",
                        );  
                    }
                    else {
                        if($response_data->multiplication_factor != "" && $response_data->offset != "" ){ 
                            if($response_data->actual_max > 0) {
                                $max =  ($response_data->actual_max * $response_data->offset) + $response_data->multiplication_factor;
                            }
                            else {
                                $max =   $response_data->actual_max;
                            }
                            if($response_data->actual_min > 0) {
                                $min =  ($response_data->actual_min * $response_data->offset) + $response_data->multiplication_factor;
                            }
                            else {
                                $min =   $response_data->actual_min;
                            }
                            if($response_data->actual_avg > 0) {
                                $avg =  ($response_data->actual_avg * $response_data->offset) + $response_data->multiplication_factor;
                            }
                            else {
                                $avg =   $response_data->actual_avg;
                            }
                        }
                        else {
                            $max =  $response_data->actual_max;
                            $min =  $response_data->actual_min;
                            $avg =  $response_data->actual_avg;   
                        }
                        $tt             =   $response_data->chartName . " (A" . DB::table("manual_readings")->where("id",$response_data->manual_readings_id)->first()->number . ")";
                        if($response_data->units != ""){
                           $tt          .=   " " . $response_data->units;
                        }
                        $data[]           =   array(
                            "chartName"         =>  $tt,
                            "date"              =>  date("Y-m-d", strtotime($response_data->date)) ,
                            "time"              =>  date("H:i:00", strtotime($response_data->time)),
                            "date_time"         =>  date("Y-m-d", strtotime($response_data->date)). " " .date("H:i:00", strtotime($response_data->time)),
                            "max"               =>  (int)$max,
                            "min"               =>  (int)$min,
                            "avg"               =>  (int)$avg,
                            "chartType"         =>  $response_data->chartType,
                            "latitude"          =>  $location->latitude ?? "",
                            "longitude"         =>  $location->longitude ?? "",
                        );
                    }
                }
                
                $this->show_data("Data fetched",$data);
            }
            else {
                $this->show_error("Vehicle or device id not matched");        
            }
        }
        $this->show_error("Vehicle id and date  compulsory");
    }
    
    function get_charts_data_1(Request $request){
        header('Content-Type: application/json; charset=utf-8');
        // CORS Headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        // header("Access-Control-Allow-Headers: Content-Type, Authorization");
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        error_reporting(0);
        error_reporting(0);
        $device_id              =   $request->devices_id == ""?"6361819":$request->devices_id;
        $requested_date         =   $request->date == ""?"2025-07-11":$request->date;
        $digital_readings       =   DB::table("manual_readings")->where("type","Digital")->orderBy("id","asc")->get();
        $analog_readings        =   DB::table("manual_readings")->where("type","Analogue")->orderBy("id","asc")->get();
        
        // Fetch distinct times
        $get_times              =   DB::table("csv_data1")
            ->selectRaw("distinct(time)")
            ->where("device_id", $device_id)
            ->where("date", $requested_date)
            ->whereRaw("(actual_min > 0 and actual_max > 0 or actual_avg > 0 or actual_value = 1)")
            ->orderBy("time", "asc")
            ->get();
        
        // timestamps
        foreach($get_times as $get_time){
            $timestamps[]   =   strtotime($get_time->time);
        }
        
        // digital readings
        foreach($digital_readings as $digital_reading) { 
                
            foreach($get_times as $get_time){
                $key                =    $digital_reading->id . "_" . $get_time->time;
                $value              =    
                    DB::table("csv_data1")
                    ->where("device_id", $device_id)
                    ->where("date", $requested_date)
                    ->where("time", $get_time->time)
                    ->where("manual_readings_id",$digital_reading->id)
                    ->first()
                    ;
                $points_arr[]       =   [
                    "time"          =>  $get_time->time,
                    "value"         =>  $value->actual_value ?? 0
                ];
            }
            $digital_arr[] = [
                "id"     => "D" . $digital_reading->number,
                "name"   => $digital_reading->name,
                "color"  => $digital_reading->stroke_color,
                "points" => $points_arr,
            ];
        }
        
        // analog readings
        foreach($analog_readings as $analog_reading){
            
            foreach($get_times as $get_time){
                $key            =    $analog_reading->id . "_" . $get_time->time;
                $value              =    
                    DB::table("csv_data1")
                    ->where("device_id", $device_id)
                    ->where("date", $requested_date)
                    ->where("time", $get_time->time)
                    ->where("manual_readings_id",$analog_reading->id)
                    ->first()
                    ;
                $points_arr[]   =   [
                    "time"      =>  $get_time->time,
                    "avg"       =>  $value->actual_avg > 0 ? $value->actual_avg : 0,
                    "max"       =>  $value->actual_max > 0 ? $value->actual_max : 0,
                    "min"       =>  $value->actual_min > 0 ? $value->actual_min : 0,
                ];
            }
            $analog_arr[] = [
                "id"     => "A" . $analog_reading->number,
                "name"   => $analog_reading->name,
                "color"  => $analog_reading->stroke_color,
                "points" => $points_arr,
            ];
        }
        
        // gps 
        foreach($get_times as $get_time){
            $devices_locations_data =   DB::table("devices_locations")->whereRaw("devices_id = '1' and date(date_time) = '$requested_date' and time(date_time) = '" . $get_time->time . "' ")->first();
            $gps_data[]         =   array(
                "time"          =>  date("H:i:s",$time),
                "lat"           =>  $devices_locations_data->latitude,
                "lng"           =>  $devices_locations_data->longitude,
            );
        }
        
        $data["times"]                      =   $timestamps;
        $data["gpsPerSecond"]               =   $gps_data;
        $data["digitalPerSecond"]           =   $digital_arr;
        $data["analogPerSecond"]            =   $analog_arr;
    
        echo $this->show_data("Data retreieved",$data);
        
        
        // // Pagination
        // $page               =   $request->page ?? 1;
        // $per_page_items     =   $request->per_page_items ?? 5;
        // $skip               =   ($page - 1) * $per_page_items;
        // // Get total and paginated readings
        // $total              =   DB::table("manual_readings")->count();
        // $last_page_no       =   ceil($total / $per_page_items);
        
        // $manual_readings = DB::table("manual_readings")
        //     ->orderBy("type", "asc")
        //     ->skip($skip)
        //     ->take($per_page_items)
        //     ->get();
        
        // // Preload all CSV data
        // $csv_records = DB::table("csv_data1")
        //     ->where("device_id", $device_id)
        //     ->where("date", $requested_date)
        //     ->get()
        //     ->groupBy(function($r) {
        //         return $r->manual_readings_id . "_" . $r->time;
        //     });
        
        // $digital_arr = [];
        // $analog_arr  = [];
        
        // foreach($manual_readings as $manual_reading){
        //     $points_arr = [];
        
        //     foreach($get_times as $get_time){
        //         $key   = $manual_reading->id . "_" . $get_time->time;
        //         $value = $csv_records[$key][0] ?? null;
        
        //         if($manual_reading->type === "Digital"){
        //             $points_arr[] = [
        //                 "time" => $get_time->time,
        //                 "value" => $value->actual_value ?? 0
        //             ];
        //         } else {
        //             $points_arr[] = [
        //                 "time" => $get_time->time,
        //                 "avg"  => $value->actual_avg > 0 ? $value->actual_avg : 0,
        //                 "max"  => $value->actual_max > 0 ? $value->actual_max : 0,
        //                 "min"  => $value->actual_min > 0 ? $value->actual_min : 0,
        //             ];
        //         }
        //     }
        
        //     if($manual_reading->type === "Digital"){
        //         $digital_arr[] = [
        //             "id"     => "D" . $manual_reading->number,
        //             "name"   => $manual_reading->name,
        //             "color"  => $manual_reading->stroke_color,
        //             "points" => $points_arr,
        //         ];
        //     } else {
        //         $analog_arr[] = [
        //             "id"     => "A" . $manual_reading->number,
        //             "name"   => $manual_reading->name,
        //             "color"  => $manual_reading->stroke_color,
        //             "points" => $points_arr,
        //         ];
        //     }
        // }

        // // gps 
        // foreach($get_times as $get_time){
        //     $devices_locations_data =   DB::table("devices_locations")->whereRaw("devices_id = '1' and date(date_time) = '$requested_date' and time(date_time) = '" . $get_time->time . "' ")->first();
        //     $gps_data[]         =   array(
        //         "time"          =>  date("H:i:s",$time),
        //         "lat"           =>  $devices_locations_data->latitude,
        //         "lng"           =>  $devices_locations_data->longitude,
        //     );
        // }
        
        
        // $data["last_page_no"]               =   $last_page_no;
        // $data["times"]                      =   $timestamps;
        // $data["gpsPerSecond"]               =   $gps_data;
        // $data["digitalPerSecond"]           =   $digital_arr;
        // $data["analogPerSecond"]            =   $analog_arr;
    
        // echo $this->show_data("Data retreieved",$data);
        
    }
     function pankaj_chart_json(){
        error_reporting(0);
        ini_set('max_execution_time', 0); // unlimited
        set_time_limit(0);
        ini_set('memory_limit', '-1'); // no memory limit


        $devices_id     =   "6363298";
        $reading_date   =   "2025-11-03";
        $json_res       =   null;
        $csv_datas      =   DB::table("csv_datas")->where("date",$date)->where("devices_serial_no",$devices_id)->whereRaw("(min > 0 or max > 0 or avg > 0")->orderBy("time","asc")->count();
        
        dd($csv_datas);
        foreach($csv_datas as $csv_data){
            $json_res[] =   array(
                "chartName" =>  $csv_data->manual_readings_type . " " . $csv_data->manual_readings_number,
                "time"      =>  $csv_data->time,
                "date"      =>  $csv_data->date,
                "min"       =>  $csv_data->min,
                "max"       =>  $csv_data->max,
                "avg"       =>  $csv_data->avg,
            );
        }
        
        echo json_encode($csv_datas);
     }
     
    function manual_readings(){
        $digital_readings           =   DB::table("manual_readings")->where("type","Digital")->orderBy("id","asc")->get();
        $analog_readings            =   DB::table("manual_readings")->where("type","Analogue")->orderBy("id","asc")->get();
        $digital_reading_arr        =   null;
        $analog_reading_arr         =   null;
        
        foreach($digital_readings as $digital_reading){
            $digital_reading_arr[]  =   array(
                "id"                =>  "D" . $digital_reading->number ,
                "name"              =>  $digital_reading->name
            );
        } 
        $data["digital_readings"]   =   $digital_reading_arr;
        
        foreach($analog_readings as $analog_reading){
            $analog_reading_arr[]   =   array(
                "id"                =>  "A" . $analog_reading->number,
                "name"              =>  $analog_reading->name
            );
        }
        $data["analog_readings"]    =   $analog_reading_arr;
        $this->show_data("Manual readings", $data);
        
    }
}