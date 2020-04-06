(function () {
    var foo = document.getElementsByClassName("vf-pn")[0];
    if (foo) {
        foo.style.position   = "absolute";
        foo.style.height     = "11px";
        foo.style.width      = "11px";
        foo.style.textIndent = "11px";
        foo.style.overflow   = "hidden";
        foo.className        = "";

        var bar = foo.getElementsByTagName("input")[0];
        if (bar) {
            bar.tabIndex  = -1;
            bar.value     = parseInt(foo.getAttribute("data-first")) + parseInt(foo.getAttribute("data-second"));
            bar.className = "";
        }
    }
})();
