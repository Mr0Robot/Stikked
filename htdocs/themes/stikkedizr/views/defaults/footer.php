			<?php $this->load->view('defaults/footer_message'); ?>
		</div>
<?php

//codemirror modes
if(isset($ace_modes)){
    echo '<div style="display: none;" id="ace_modes">' . json_encode($ace_modes) . '</div>';
}

//stats
$this->load->view('defaults/stats');

//Javascript
/*{$this->carabiner->js('modernizr.js');
$this->carabiner->js('jquery-1.10.2.min.js');
$this->carabiner->js('bootstrap.min.js');
$this->carabiner->js('jquery.timers.js');
$this->carabiner->js('codemirror/lib/codemirror.js');


$this->carabiner->js('stikked.js');

$this->carabiner->display('js');
}*/
?>
	</body>
</html>
