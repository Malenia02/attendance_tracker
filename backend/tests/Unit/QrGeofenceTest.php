<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\QrAttendanceController;
use ReflectionMethod;
use Tests\TestCase;

class QrGeofenceTest extends TestCase
{
    public function test_same_office_coordinates_have_zero_distance(): void
    {
        $distance = $this->distance(7.7845, 122.5868, 7.7845, 122.5868);

        $this->assertSame(0.0, $distance);
    }

    public function test_distance_calculation_detects_location_outside_radius(): void
    {
        $distance = $this->distance(7.7860, 122.5868, 7.7845, 122.5868);

        $this->assertGreaterThan(100, $distance);
        $this->assertLessThan(200, $distance);
    }

    private function distance(
        float $latitude,
        float $longitude,
        float $officeLatitude,
        float $officeLongitude
    ): float {
        $method = new ReflectionMethod(QrAttendanceController::class, 'distanceInMeters');

        return $method->invoke(
            new QrAttendanceController,
            $latitude,
            $longitude,
            $officeLatitude,
            $officeLongitude
        );
    }
}
