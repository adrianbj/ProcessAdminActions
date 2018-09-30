$(document).ready(function(){

    // remove scripts, because they've already been executed since we are manipulating the DOM below (WireTabs)
    // which would cause any scripts to get executed twice

    $t = $("#AdminActionsList");
    //$t.find("script").remove();

    $t.WireTabs({
        items: $(".Inputfields li.WireTab"),
        rememberTabs: true
    });

});