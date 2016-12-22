$(document).ready(function() {
	var adminActionsCode = ace.edit("actionCodeViewer");
	adminActionsCode.setReadOnly(true);
	adminActionsCode.setTheme("ace/theme/tomorrow_night");
	adminActionsCode.getSession().setMode({path:"ace/mode/php", inline:true});
    adminActionsCode.container.style.lineHeight = 1.8;
    adminActionsCode.setFontSize(13);
    adminActionsCode.setShowPrintMargin(false);
    adminActionsCode.$blockScrolling = Infinity; //fix deprecation warning
});