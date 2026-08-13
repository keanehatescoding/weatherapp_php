# PHP Weather App

## Description
This is a simple, responsive PHP weather application using OpenWeatherMap API to fetch and display basic weather information to any location supported by the OpenWeatherMap API.

## 🔧 Features

1. **Current Weather**  
   - Displays temperature, humidity, description, and an emoji for the current conditions.
3. **Input Validation & Error Handling**  
   - Ensures city names contain only letters, spaces, hyphens, and apostrophes.  
   - User-friendly error messages for invalid input or HTTP/API errors.
4. **XSS Protection**  
   - All user inputs are validated and never directly injected as HTML even when fetching results from the OpenWeatherMap API we sanitize input to ensure even if OpenWeatherMap is hacked we are not susceptible to Cross Site Scripting Attacks. 
   - Displayed data comes strictly from sanitized API responses or controlled DOM updates.
   - Client Side filtering to prevent the client from injecting malicious input and also server side to mitigate this.
5. **Rate Limiting Awareness**  
   - Uses a single API key under the free tier limits and therefore uses IP based rate limiting to minimise the number of Denial of Service Attacks and excessive API usage bills.  
   - **30 requests per IP per 24 h** (enforced via a file-based per-IP counter in `./var/ratelimit`, with a session-backed fallback if the filesystem is read-only).  
   - **Response caching** (~10 min TTL) reuses cached results for repeated lookups to further reduce API quota usage.  
   - Avoids excessive polling; only fetches data on explicit form submission.
---

## ▶️ Getting Started

### Pre-requites
   
#### Git
- Ensure git is installed on your machine to clone this repo. The official installation page for git is [here](https://git-scm.com/downloads).
  
#### PHP HTTP Server
- This project uses PHP as it's backend therefore we need a server that can handle php files. You can install xammp or php >= 7 or any other http server that supports php.

### Installation Guide

Clone this repo
```bash
git clone https://github.com/keanehatescoding/weatherapp_php.git
```
go to the weatherapp directory
```bash
cd weatherapp_php
```
To be able to send and receive weather information you need an API key. Navigate to this [link](https://openweathermap.org) to create your openweathermap account and get your API key. This should take approximately 20 mins. Once you get your API key then create an ENIVORNMENTAL VARIABLE called OPENWEATHERMAP_API_KEY and export it i.e for Linux this would be:
```bash
export OPENWEATHERMAP_API_KEY="* your API key here *"
```
ON Windows
``` powershell
[Environment]::SetEnvironmentVariable("OPENWEATHERMAP_API_KEY", "YOUR_API_KEY", "User")
```

If you have xammpp you can move all this files to /htdocs directory in xammpp while if you have php>= 7 then just type
```
php -S localhost:8000
```
If port 8000 is free then it will attempt to run this project from that post. All you need now is go open http://localhost:8000 from your browser or whatever port your php server is running on.

## 📝 License

This project is licensed under the GNU General Public License version 2.1 (GPL-2.1).  
You may obtain a copy of the license at:

- https://www.gnu.org/licenses/old-licenses/gpl-2.1.html

```text
GNU GENERAL PUBLIC LICENSE
                       Version 2.1, February 1999

Copyright (C) <year> <author>
Everyone is permitted to copy and distribute verbatim copies
of this license document, but changing it is not allowed.

[Full text at https://www.gnu.org/licenses/old-licenses/gpl-2.1.html]
```
By using, modifying or distributing this software, you agree to all terms and conditions listed in GPL-2.1.

## 🙏 Acknowledgements

Tribute to [OpenWeatherMap.org](https://openweathermap.org) for their robuse and free tier API which has enabled us to build this project and learn a lot.

Much appreciation to the following parties for their contribution.

[@keanehatescoding](https://github.com/keanehatescoding)

[@easter-m](https://github.com/easter-m)

[yo-yo-05](https://github.com/yo-yo-05)

[@Hopeyriizeis7](https://github.com/Hopeyriizeis7)

[@mulle-emmanuel](https://github.com/mulle-emmanuel)
