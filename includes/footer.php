            </div><!-- /.container-xl -->
        </div><!-- /.page-body -->

        <footer class="footer footer-transparent d-print-none mt-auto">
            <div class="container-xl">
                <div class="row text-center align-items-center">
                    <div class="col-12">
                        <p class="mb-0 text-muted small">
                            &copy; <?= date('Y') ?> <?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_HTML5) ?> &mdash; SUNY Purchase
                        </p>
                    </div>
                </div>
            </div>
        </footer>
    </div><!-- /.page-wrapper -->
</div><!-- /.wrapper -->

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script>
(function () {
    var p = document.getElementById('dd-preloader');
    function hide() { if (p) { p.style.opacity = '0'; setTimeout(function () { if (p) p.remove(); }, 320); } }
    if (document.readyState === 'complete') { hide(); }
    else { window.addEventListener('load', hide); }
    // Safety: always hide after 3 s even if load never fires
    setTimeout(hide, 3000);
}());
</script>
</body>
</html>
