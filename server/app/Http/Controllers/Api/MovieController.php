<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Http\Resources\MovieResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MovieController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Movie::with(['screenings.room', 'screenings.bookings']);

        if ($request->has('week_number')) {
            $query->whereHas('screenings', function ($query) use ($request) {
                $query->where('week_number', $request->week_number);
            })->with(['screenings' => function ($query) use ($request) {
                $query->where('week_number', $request->week_number)
                      ->with(['room', 'bookings']);
            }]);
        }

        $movies = $query->get();

        return response()->json($movies->map(function ($movie) {
            return [
                'id' => $movie->id,
                'title' => $movie->title,
                'description' => $movie->description,
                'image_path' => $movie->image_path,
                'duration' => $movie->duration,
                'genre' => $movie->genre,
                'release_year' => $movie->release_year,
                'screenings' => $movie->screenings->map(function ($screening) {
                    $unavailableSeats = [];
                    foreach ($screening->bookings as $booking) {
                        if ($booking->status !== 'cancelled' && $booking->seats) {
                            $seats = is_string($booking->seats) ? json_decode($booking->seats, true) : $booking->seats;
                            foreach ($seats as $seat) {
                                $unavailableSeats[] = [
                                    'row' => $seat['row'],
                                    'seat' => $seat['seat']
                                ];
                            }
                        }
                    }
                    
                    return [
                        'id' => $screening->id,
                        'room' => [
                            'rows' => $screening->room->rows,
                            'seatsPerRow' => $screening->room->seats_per_row
                        ],
                        'start_time' => $screening->start_time->format('H:i'),
                        'date' => $screening->date,
                        'week_number' => $screening->week_number,
                        'week_day' => $screening->week_day,
                        'bookings' => $unavailableSeats
                    ];
                })
            ];
        }));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'duration' => 'required|integer|min:1',
                'director' => 'required|string|max:255',
                'genre' => 'required|string|max:255',
                'release_year' => 'required|integer|min:1900|max:' . (date('Y') + 1)
            ]);

            $imagePath = $request->file('image')->store('movies', 'public');

            $movie = Movie::create([
                'title' => $request->title,
                'description' => $request->description,
                'image_path' => $imagePath,
                'duration' => $request->duration,
                'director' => $request->director,
                'genre' => $request->genre,
                'release_year' => $request->release_year
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Movie added successfully!',
                'data' => new MovieResource($movie)
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Movie addition failed due to validation errors',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add movie. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Movie $movie)
    {
        $movie->load(['screenings.room', 'screenings.bookings']);
        return response()->json([
            'id' => $movie->id,
            'title' => $movie->title,
            'description' => $movie->description,
            'image_path' => $movie->image_path,
            'duration' => $movie->duration,
            'genre' => $movie->genre,
            'release_year' => $movie->release_year,
            'screenings' => $movie->screenings->map(function ($screening) {
                $unavailableSeats = [];
                foreach ($screening->bookings as $booking) {
                    if ($booking->status !== 'cancelled' && $booking->seats) {
                        $seats = is_string($booking->seats) ? json_decode($booking->seats, true) : $booking->seats;
                        foreach ($seats as $seat) {
                            $unavailableSeats[] = [
                                'row' => $seat['row'],
                                'seat' => $seat['seat']
                            ];
                        }
                    }
                }
                
                return [
                    'id' => $screening->id,
                    'room' => [
                        'rows' => $screening->room->rows,
                        'seatsPerRow' => $screening->room->seats_per_row
                    ],
                    'start_time' => $screening->start_time->format('H:i'),
                    'date' => $screening->date,
                    'week_number' => $screening->week_number,
                    'week_day' => $screening->week_day,
                    'bookings' => $unavailableSeats
                ];
            })
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Movie $movie)
    {
        $request->validate([
            'title' => 'string|max:255',
            'description' => 'string',
            'image' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'duration' => 'integer|min:1',
            'director' => 'string|max:255',
            'genre' => 'string|max:255',
            'release_year' => 'integer|min:1900|max:' . (date('Y') + 1)
        ]);

        if ($request->hasFile('image')) {
            // Delete old image
            if ($movie->image_path) {
                Storage::disk('public')->delete($movie->image_path);
            }
            $imagePath = $request->file('image')->store('movies', 'public');
            $movie->image_path = $imagePath;
        }

        $movie->update($request->except('image'));

        return new MovieResource($movie);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Movie $movie)
    {
        if ($movie->image_path) {
            Storage::disk('public')->delete($movie->image_path);
        }
        
        $movie->delete();
        return response()->json(null, 204);
    }

    /**
     * Get movies by week number.
     */
    public function byWeek(Request $request)
    {
        $weekNumber = $request->query('week_number');
        
        if (!$weekNumber) {
            return response()->json([
                'status' => 'error',
                'message' => 'Week number is required'
            ], 400);
        }

        $movies = Movie::with(['screenings' => function ($query) use ($weekNumber) {
            $query->where('week_number', $weekNumber)
                  ->with(['room', 'bookings']);
        }])->get();

        return response()->json($movies->map(function ($movie) {
            return [
                'id' => $movie->id,
                'title' => $movie->title,
                'description' => $movie->description,
                'image_path' => $movie->image_path,
                'duration' => $movie->duration,
                'genre' => $movie->genre,
                'release_year' => $movie->release_year,
                'screenings' => $movie->screenings->map(function ($screening) {
                    $unavailableSeats = [];
                    foreach ($screening->bookings as $booking) {
                        if ($booking->status !== 'cancelled' && $booking->seats) {
                            $seats = is_string($booking->seats) ? json_decode($booking->seats, true) : $booking->seats;
                            foreach ($seats as $seat) {
                                $unavailableSeats[] = [
                                    'row' => $seat['row'],
                                    'seat' => $seat['seat']
                                ];
                            }
                        }
                    }
                    
                    return [
                        'id' => $screening->id,
                        'room' => [
                            'rows' => $screening->room->rows,
                            'seatsPerRow' => $screening->room->seats_per_row
                        ],
                        'start_time' => $screening->start_time->format('H:i'),
                        'date' => $screening->date,
                        'week_number' => $screening->week_number,
                        'week_day' => $screening->week_day,
                        'bookings' => $unavailableSeats
                    ];
                })
            ];
        }));
    }
}
