<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Boy Barbershop</title>

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    />

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
<nav class="navbar navbar-dark bb-navbar shadow-sm">
    <div class="container-fluid bb-navbar-inner">
        <div class="bb-logo-wrap">
            <img src="assets/img/logo.png" alt="Boy Barbershop logo" />
            <div class="bb-brand text-white">
                BOY BARBERSHOP
                <span>cashier & earnings</span>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid bb-shell py-4">
    <div class="row g-3">
        <aside class="col-12 col-md-3 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase fw-semibold mb-3 small">
                        Navigation
                    </h6>
                    <nav class="nav flex-column bb-sidebar small">
                        <a href="index.php" class="nav-link active">
                            <span>Dashboard (coming)</span>
                        </a>
                        <a href="#" class="nav-link disabled">
                            <span>Barbers</span>
                        </a>
                        <a href="#" class="nav-link disabled">
                            <span>Services</span>
                        </a>
                        <a href="#" class="nav-link disabled">
                            <span>Sales</span>
                        </a>
                        <a href="#" class="nav-link disabled">
                            <span>Expenses</span>
                        </a>
                    </nav>
                </div>
            </div>
        </aside>

        <main class="col-12 col-md-9 col-lg-10 bb-main">
            <div class="bb-main-card card border-0 shadow-sm h-100">
                <div class="card-body">
