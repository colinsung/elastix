$(document).ready(function(){
	
    if($("#primanager option:selected").val()=="yes") 
	    $(".privacy").css("display", "");
	else
	    $(".privacy").css("display", "none");
	
/*	$("#primanager").change(function(){
        if($("#primanager option:selected").val()=="yes");
            $(".privacy").attr("disabled", "");
        else
            $(".privacy").attr("disabled", "disabled");
	});*/
	
	$("#goto").change(function(){
        var option = $("#goto option:selected").val();
        //obtenemos las opciones correspondientes a esa categoria
        var arrAction = new Array();
        arrAction["action"]   = "get_destination_category";
        arrAction["menu"]     = "ivr";
        arrAction["rawmode"]  = "yes";
        arrAction["option"]  = option;
        request("index.php",arrAction,false,
        function(arrData,statusResponse,error)
        {
            $("#destination option").remove();
            if(error!=""){
                alert(error);
            }else{
                for( x in arrData ){
                    var valor=arrData[x];
                    $("#destination").append("<option value="+x+">"+valor+"</option>"); 
                }
            }
        });       
    });
});





