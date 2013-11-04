var frmvalidator = null;
$( document ).ready(function() {

/* oculta el mensaje que aprece al cargar la pagina, siempre y cuando haya un error con el efecto slide*/
    $(".close").click(function() {
        $("#initial_message_area").slideUp();   
        $("#message_area").slideUp();      
    });

    $(".close").click(function() {
        $("#message_area").slideUp();      
    });
    
});

function editFaxExten(){
    showElastixUFStatusBar("Saving...");
    var arrAction = new Array();
    arrAction["menu"]="my_fax";
    arrAction["action"]="editFaxExten";
    arrAction["CID_NAME"]=$("input[name='CID_NAME']").val();
    arrAction["CID_NUMBER"]=$("input[name='CID_NUMBER']").val();
    arrAction["COUNTRY_CODE"]=$("input[name='COUNTRY_CODE']").val();
    arrAction["AREA_CODE"]=$("input[name='AREA_CODE']").val();
    arrAction["FAX_SUBJECT"]=$("input[name='FAX_SUBJECT']").val();
    arrAction["FAX_CONTENT"]=$("textarea[name='FAX_CONTENT']").val();
    
    arrAction["rawmode"]="yes";
    request("index.php", arrAction, false,
        function(arrData,statusResponse,error){
            hideElastixUFStatusBar();
            if (error != '' ){
                $("#message_area").slideDown();
                $("#msg-text").removeClass("alert-success").addClass("alert-danger");
                $("#msg-text").html(error['stringError']);
                // se recorre todos los elementos erroneos y se agrega la clase error (color rojo)
                $(".flag").removeClass("has-error");
                $(".visible-tooltip").removeClass("visible-tooltip").addClass("hidden-tooltip");
                for(var i=0;i<error['field'].length; i++){     
                    $("[name='"+error['field'][i]+"']").parents(':first').addClass("has-error flag");
                    $("[name='"+error['field'][i]+"']").next().tooltip().removeClass("hidden-tooltip").addClass("visible-tooltip");
                }
            }else{
                //se elimina el borde rojo a los campos que estaban erroneos, y que hayan sido ingresados 
                $(".flag").removeClass("has-error");
                $(".visible-tooltip").removeClass("visible-tooltip").addClass("hidden-tooltip");
                $("#msg-text").removeClass("alert-danger").addClass("alert-success");
                $("#msg-text").html(arrData);
                $("#message_area").slideDown();
            }
    });
}

/*funcion que chequea constantemente el estado del fax*/
checkFaxStatus();
function checkFaxStatus()
{
    var arrAction        = new Array();
    arrAction["action"]  = "checkFaxStatus";
    arrAction["menu"]    = "my_fax";
    arrAction["rawmode"] = "yes";

    request("index.php",arrAction,true,
            function(arrData,statusResponse,error)
            {
                if(statusResponse=="CHANGED"){
                    $(".fax-status").html(arrData);
                }
                
            });
}


