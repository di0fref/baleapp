<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>🌾 Höbalsapp v1.4</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/templates.js" defer></script>
    <script src="assets/app.js" defer></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <style>
        tr{
            @apply:center;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100 transition-colors duration-500">

<div class="max-w-6xl mx-auto p-4 ">
    <!-- 🌾 Responsive top bar -->
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 mb-4">
        <h1 class="text-2xl font-bold text-center sm:text-left">🌾 Höbalsapp</h1>

        <div class="flex flex-wrap justify-center sm:justify-end gap-2 " id="topNav">
            <button id="reportBtn"
                    class="bg-indigo-600 text-white text-xs sm:text-sm px-3 py-1 rounded w-[46%] sm:w-auto">
                📊 Rapport
            </button>
            <button id="forecastBtn"
                    class="bg-yellow-600 text-white text-xs sm:text-sm px-3 py-1 rounded w-[46%] sm:w-auto">
                💰 Prognos
            </button>
            <button id="themeToggle"
                    class="bg-gray-200 dark:bg-gray-700 text-xs sm:text-sm px-3 py-1 rounded w-[46%] sm:w-auto">
                🌙
            </button>
            <button id="logoutBtn"
                    class="bg-red-600 hover:bg-red-700 text-white text-xs sm:text-sm px-3 py-1 rounded w-[46%] sm:w-auto">
                Logga ut
            </button>
        </div>
    </div>


    <!-- App content rendered here -->
    <div id="app"></div>
</div>

<div id="toastContainer" class="fixed bottom-4 right-4 flex flex-col gap-2 z-50 pointer-events-none"></div>
</body>
</html>
