(function () {
    const joe = document.getElementsByClassName("vf-pn");
    if (joe) {
        let foo, bar;
        for (let i = joe.length - 1; i >= 0; --i) {
            foo = joe[i];

            foo.style.position   = "absolute";
            foo.style.height     = "11px";
            foo.style.width      = "11px";
            foo.style.textIndent = "11px";
            foo.style.overflow   = "hidden";
            foo.className        = "";

            bar = foo.getElementsByTagName("input")[0];
            if (bar) {
                bar.tabIndex  = -1;
                bar.value     = parseInt(foo.getAttribute("data-first")) + parseInt(foo.getAttribute("data-second")).toString();
                bar.className = "";
            }
        }
    }
})();
