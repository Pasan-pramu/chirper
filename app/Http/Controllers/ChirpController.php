<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Chirp;

class ChirpController extends Controller
{
    /**
     * Display a listing of the resource.
     */
   
public function index()
{
    $chirps = Chirp::with('user')
    ->latest()
    ->take(50)
    ->get();

    return view('home', ['chirps' => $chirps]);
}
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
   
public function store(Request $request)
{
    // Validate the request
    $validated = $request->validate([
        'message' => 'required|string|max:255',
    ]);

    // Create the chirp (no user for now - we'll add auth later)
    \App\Models\Chirp::create([
        'message' => $validated['message'],
        'user_id' => null, // We'll add authentication in lesson 11
    ]);

    // Redirect back to the feed
    return redirect('/')->with('success', 'Chirp created!');
}

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
