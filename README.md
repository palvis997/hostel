# Kirinyaga University | Smart Campus Accommodation & Facility Management System

SPI 2301 Web Application Development II, semester project.

## Setup on XAMPP

1. Copy the `hostel` folder to `C:\xamp\htdocs\hostel` (or your XAMPP `htdocs` folder).
2. Start Apache and MySQL in the XAMPP control panel.
3. In phpMyAdmin, create a database named `smart_campus`, select it in the sidebar, then import `database.sql`. The SQL file does not create or select a database, so it can also be imported into a provider-assigned database name. Importing it drops and recreates every table, resetting its data.
4. Open http://localhost/hostel/

Database connection settings are at the top of `db.php`.

## How an application works

1. Students register with a Kirinyaga email address, ending in @student.kyu.ac.ke or @kyu.ac.ke.
2. A student applies for a room and gives the payment method and the payment reference, such as an M-Pesa code (10 letters and numbers).
3. The warden verifies the payment evidence, then approves the application. Rejecting an application needs a reason, which the student sees on their dashboard.
4. The reports summarise booked hostels, occupancy, fee collection and maintenance, and print cleanly.

## Demo accounts

| Role | Sign-in page | Login | Password |
|---|---|---|---|
| Student with an approved room | `login.php` | peter@student.kyu.ac.ke or CT100/G/10001/24 | Student@123 |
| Student with a rejected application | `login.php` | ruth@student.kyu.ac.ke or CT100/G/10006/24 | Student@123 |
| Student with a pending application | `login.php` | david@student.kyu.ac.ke or CT100/G/10005/24 | Student@123 |
| Warden / administrator | `admin_login.php` | warden@kyu.ac.ke | Admin@123 |

Change these passwords before the system goes live.

## Photos (`assets/images/`)

| File | Used on |
|---|---|
| campus-modern-students.jpg | Homepage carousel, slide 1 |
| campus-lawn-building.jpg | Homepage carousel, slide 2 |
| campus-bright-block.jpg | Homepage carousel, slide 3 |
| hostel_block_a.jpg | Hostel card: Mt Kenya Block A |
| hostel_block_b.jpg | Hostel card: Kirinyaga Block B |
| hostel_block_c.jpg | Hostel card: Baringo Block C |
| banner-courtyard.jpg | "Browse Available Rooms" banner |
| banner-atrium.jpg | "Find & Book Your Ideal Campus Room" banner |
| interior-study-nook.jpg | Room cards and the allocated-room photo |
| banner-stairwell.jpg | Student dashboard banner |
| banner-stone-balcony.jpg | Warden portal banner |
| banner-portico.jpg | Reports banner |
| auth-evening-login.jpg | Student login |
| auth-evening-register.jpg | Student registration |
| auth-evening-admin.jpg | Administrator login |
| spare-evening-1.jpg, spare-evening-2.jpg | Not used yet |

Hostel cards read their photo from the `image` column of the `hostels` table. Bedroom photos are optional: add `room-2bed.jpg` (rooms of one or two beds) and `room-4bed.jpg` (rooms of three or more beds) to `assets/images/` and they are used automatically.

## Hosting on Render

The repository includes a `Dockerfile` for the PHP application. Connect the GitHub repository to Render as a Docker Web Service, use the `main` branch, and leave the build and start commands blank.

The app requires an external MySQL database; Render's built-in database is PostgreSQL and is not compatible with this project. Create a MySQL database with a provider that accepts connections from Render, select that database in its import tool, then import `database.sql`. Do not use the local XAMPP database host (`localhost` or `127.0.0.1`) in Render.

Add these environment variables to the Render service using the values supplied by the MySQL provider:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

The database settings can also be configured directly at the top of `db.php`. Do not commit production credentials. The SQL import drops and recreates the application's tables, so do not import it again after the site has real user data unless you intend to reset that data.

Before making the site public, replace or remove the demo accounts and change their passwords.
