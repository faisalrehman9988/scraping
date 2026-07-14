# Web Scraper

A PHP-based web scraper that collects live webcam stream data from **liveworldwebcams.com** and stores it in a MySQL database, with a simple web interface to run the scraper and browse the results.

## Features

- Web scraping using PHP and cURL
- Automatic category discovery and data extraction
- Data storage in a MySQL database
- Progress tracking with real-time updates
- Stop/Resume scraping functionality
- REST API endpoints for accessing scraped data
- Token-based authentication for API requests
- API testing and verification using Postman
- JSON response format for easy integration with frontend applications
- User-friendly data presentation interface

## Database

Scraped data is stored in a MySQL database (`scraper`), in a table called **`live_streams`**. Each row represents one webcam/stream, with the following columns:

| Column | Description |
|---|---|
| id | Unique row ID |
| title | Name of the cam/location |
| page_url | Original webcam page URL that was scraped |
| stream_url | Direct stream/video URL (e.g. YouTube embed link) |
| country | Country the webcam is located in |
| token_value | Security token used to authorize API requests |

This table is what the frontend and API endpoints read from to display and serve the scraped streams.

## Technologies

- PHP
- MySQL
- cURL
- HTML/CSS/Bootstrap
- JavaScript

## Installation

1. Clone the repository
2. Import the database
3. Configure `db.php`
4. Run through XAMPP
