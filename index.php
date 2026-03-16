<?php
// Later: require 'connection.php'; and route pages.
?>
<?php include 'partials/header.php'; ?>

<h1 class="h4 mb-3">Welcome to Boy Barbershop System</h1>
<p class="text-muted mb-4">
    Bootstrap is now set up. Next steps are to build Phase 1:
    barbers, services, sales, and basic earnings (see `files/development_phases.md`).
</p>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title">Barbers</h5>
                <p class="card-text small text-muted">
                    Manage barbers and their percentage shares.
                </p>
                <button class="btn btn-sm btn-dark" disabled>Coming soon</button>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title">Sales</h5>
                <p class="card-text small text-muted">
                    Record daily sales and compute earnings.
                </p>
                <button class="btn btn-sm btn-dark" disabled>Coming soon</button>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title">Expenses</h5>
                <p class="card-text small text-muted">
                    Track expenses to see real profit.
                </p>
                <button class="btn btn-sm btn-dark" disabled>Planned (Phase 2)</button>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>

