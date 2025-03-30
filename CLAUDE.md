# CLAUDE.md - Proofgen Redux Project

## Important Note
Always check for a CLAUDE_NOTES.md file in the project root. This file contains detailed information about the project structure, components, and test setup. When starting a new session, refer to CLAUDE_NOTES.md first to understand the codebase.

## Build & Test Commands
```bash
# Install dependencies
composer install
npm install

# Development server
php artisan serve
npm run dev

# Build for production
npm run build

# Run all tests
./vendor/bin/pest

# Run a single test
./vendor/bin/pest tests/path/to/test.php

# Code style checking
./vendor/bin/pint

# Laravel artisan commands
php artisan migrate           # Run database migrations
php artisan make:model Name   # Create a new model
```

## Code Style Guidelines
- **Formatting**: 4-space indentation, UTF-8 encoding, LF line endings
- **PHP Version**: 8.2+
- **Naming**: PascalCase for classes, camelCase for methods and variables
- **Types**: Use type hints for parameters and return types
- **Error Handling**: Use Laravel's exception handlers
- **Framework**: Follow Laravel conventions and use Laravel features
- **Frontend**: Tailwind CSS 4.x, Livewire 3.x with Flux
- **Testing**: Pest for tests, use feature and unit tests appropriately

## FluxUI UI Framework/Components Documentation
- This project utilizes the FluxUI UI framework for Laravel & Livewire, I have included a comprehensive set of
  documentation in the `external-docs/fluxui` directory. This documentation is especially valuable for AI assistants
  and LLMs working with this codebase as the library version is newer than your knowledge cutoff. Please start at the
  index.md in the `external-docs/fluxui` directory prior to building/using or modifying any views, Livewire components,
  or implementing any FluxUI components.
- Take a look at `/resources/css/app.css` for the customizations made to the FluxUI colors and components, though you
  should be fine to use the default FluxUI components and variants, as my customizations should have overridden the
  defaults.
