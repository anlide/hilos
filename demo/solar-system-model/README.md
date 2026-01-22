# Solar System Model Demo Project

**Complexity: 4/5**

Demo project demonstrating interactive 3D solar system model with admin panel.

## What is this?

Static website with comprehensive admin panel for managing parameters of planets and their moons.
Admin can set parameters for all celestial bodies: size, distance from Sun, rotation speed,
orbital period, orbital inclination, textures, colors, atmospheric effects.

Simple browser 3D engine (Three.js or similar) renders the entire solar system in real-time.
3D visualization of planets, moons, orbits, asteroids, comets.
Realistic lighting from Sun, shadows, reflections, atmospheric effects.

### Features

- **Admin panel**: Comprehensive admin interface for managing planet and moon parameters
- **3D visualization**: Browser-based 3D engine renders solar system in real-time
- **Real-time updates**: Changes in admin panel immediately reflected in 3D model through WebSocket
- **Navigation UI**: Zoom, pan, rotate camera, switch between views (top, side, free camera)
- **Planet focus**: Focus on specific planet, follow planet, time acceleration/deceleration
- **Orbit display**: Display orbits, labels, names of planets and moons
- **Texture management**: Upload custom textures, configure colors, atmospheric effects
- **Physical simulation**: Planets move in orbits with correct periods, time acceleration for observation
- **Accurate parameters**: Orbital parameters based on real data

### Technical Highlights

- 3D graphics work
- Browser 3D engines
- Parameter management through admin panel
- Real-time 3D scene updates
- Physical simulation
- Interactive 3D space navigation

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
