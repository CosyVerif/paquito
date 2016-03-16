<?php

namespace Paquito\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Input\ArrayInput;

class Prune extends Command
{
	private $logger = null;
    private $to_prune = array();

	protected function configure()
	{
		$this
			->setName('prune')
			->setDescription('Prune a structure')
			->addArgument(
				'input',
				InputArgument::REQUIRED,
				'Name of the YAML file'
			)
            /*->addArgument(
				'output',
				InputArgument::OPTIONAL,
				'Name of a YaML file'
			)*/
            ->addArgument(
                'target',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Distribution on which normalize paquito.yaml'
            );
	}

	// Defines the applications variables $dist_name, $dist_version and $dist_arch
	protected function getDist()
    {
        // Init
        $supported_distributions = $this->getApplication()->conf['Distribution'];

        // Perform some very rudimentary platform detection
        $get_lsb_distribution = ucfirst(rtrim(shell_exec('lsb_release -si 2>&1')));
        if($get_lsb_distribution != NULL) // Check if lsb_release exist
        {
           if(!in_array($get_lsb_distribution, $supported_distributions)) {
               $this->logger->error($this->getApplication()->translator->trans('prune.distribution'));
               exit(-1);
           }
           
           // Set distribution
           $this->getApplication()->dist_name = $get_lsb_distribution;

           $get_lsb_version = ucfirst(rtrim(shell_exec('lsb_release -sc 2>&1')));
           if($get_lsb_version != NULL)
           {
                if(!in_array($get_lsb_version, $this->getApplication()->conf[$get_lsb_distribution]['Version'])) {
                    $this->logger->error($this->getApplication()->translator->trans('prune.version'));
                    exit(-1);
                }
                
                $this->getApplication()->dist_version = $get_lsb_version;
           }
                
           //lsb_release -sc ne fonctionne pas => regarder le fichier specifique à la distribution
           // TODO : Gerer les différents cas dynamiquement (BTW: archlinux n'a pas de différentes versions)
           else
           {
                switch($get_lsb_distribution)
                {
                    case 'Centos':
                            if(is_file('/etc/centos-release') || is_file('/etc/redhat-release'))
                            {
                                //TODO: Install Centos and check those files => get current version
                            }
                    break;
                    
                    default:
                }
           }
        }
        
        // No output from lsb_release command
        // Parse /etc/os-release first, then parse specific file if we do not have enough info
        else
        {
            if(is_file('/etc/os-release'))
            {
                $arr_os_release = parse_ini_file('/etc/os-release'); // We suppose the file is well formated
                
                $get_ini_distribution = ucfirst($arr_os_release['ID']);
                switch($get_ini_distribution)
                {
                    case 'Debian':
                        preg_match('/[a-z]+/', $arr_os_release['VERSION'], $match);
                        $this->getApplication()->dist_name = 'Debian';
                        $this->getApplication()->dist_version = ucfirst($match[0]);
                    break;
                    
                    case 'Arch':
                        // TODO : Install on Archlinux the package "filesystem" <== why ? 
                        $this->getApplication()->dist_name = 'Archlinux';
                    break;
                    
                    case 'Centos':
                        preg_match('/[0-9](\.[0-9])?/', $array_ini['VERSION'], $match);
                        $this->getApplication()->dist_name = 'Centos';
                        if(strlen($match[0]) == 1)
                            $this->getApplication()->dist_version = $match[0]."0";
                    break;
                    
                    default:
                        $logger->error($this->getApplication()->translator->trans('prune.exist'));
				        exit(-1);
                }
            }
            
            // Parse specific file
            else
            {
                // Check archlinux file. Archlinux is only available in one version and on 64 bits 
                if(is_file('/etc/arch-release'))
                    $this->getApplication()->dist_name = 'Archlinux';
                    
                // Check centos or redhat file
                else if(is_file('/etc/centos-release') || is_file('/etc/redhat-release'))
                {
                    $this->getApplication()->dist_name = 'Centos';
                    
                    // TODO : Install and get the version
                    // Read the content of the file /etc/centos-release
                    /*if (($version = file_get_contents('/etc/centos-release')) === FALSE) {
                        $logger->error($this->getApplication()->translator->trans('prune.read', array('%file%' => '/etc/centos-release')));
                        return -1;
                    }
                    
                    // Get the version of the Centos distribution
                    preg_match('/[0-9](\.[0-9])?/', $version, $match);
                    $this->getApplication()->dist_version = $match[0];*/
                }
                
                else if(is_file('/etc/debian_version'))
                {
                    $this->getApplication()->dist_name = 'Debian';
                    if(($version = file_get_contents('/etc/debian_version')) === FALSE) {
                        $this->logger->error($this->getApplication()->translator->trans('prune.read', array('%file%' => '/etc/centos-release')));
                        return -1;
                    }
                    $this->getApplication()->dist_version = rtrim($version);
                }
            }
         }
	}

	/* Prune a 'Packages' node with current distribution ($dist_name),
	 * version ($dist_version)
	 * @param $pkg_node : 'Packages' node */
	protected function prune_node($pkg_node) {
		// my_distribution should be const
        $my_distribution = array('Name' => $this->getApplication()->dist_name,
                                 'Version' => $this->getApplication()->dist_version);
                                 
		$pruned_pkg_node = $pkg_node;
        $key_dependencies = array('Build', 'Runtime', 'Test');
        
		foreach ($pkg_node as $pkg_name => $value) {
			$cur_pkg =& $pkg_node[$pkg_name];
            
			// For each field (in others words 'Build', 'Runtime' and 'Test')
			for ($i = 0; $i < 3; ++$i) {
                
                if(!isset($cur_pkg[$key_dependencies[$i]]))
                    continue;

                $cur_dep =& $cur_pkg[$key_dependencies[$i]]['Dependencies'];
                
                $cur_pruned_dep =& $pruned_pkg_node[$pkg_name][$key_dependencies[$i]]['Dependencies'];
                $cur_pruned_dep = array();
                
                // If there are dependencies in the field Build/Runtime/Test
				if (isset($cur_dep)) { 
					// Remove the 'Dependencies' node in the pruned package node
					unset($pruned_pkg_node['Packages'][$pkg_name][$key_dependencies[$i]]['Dependencies']);
                    
					// For each node in 'Dependencies' node
					foreach($cur_dep as $dep_name => $d_value)
                    {
                        if(isset($cur_dep[$dep_name][$my_distribution['Name']]['All']))
                            $dep_for_my_dist = $cur_dep[$dep_name][$my_distribution['Name']]['All'];
                        else {
                            if($my_distribution['Version'] != null) {
                                print_r($cur_dep[$dep_name][$my_distribution['Name']]['Version']);
                                // echo "Version : ".array_keys($cur_dep[$dep_name][$my_distribution['Name']]['Version'])."\n";
                                //if(!in_array($to_push, array_keys($cur_dep[$dep_name][$my_distribution['Name']]['Version'])))
                                $dep_for_my_dist = $cur_dep[$dep_name][$my_distribution['Name']]['Version'];
                            }
                            else
                                $dep_for_my_dist = $cur_dep[$dep_name][$my_distribution['Name']];
                        }
                            
                        if($dep_for_my_dist != "<none>")
                            array_push($cur_pruned_dep, $dep_for_my_dist);
                    }
                }
                
                // the distribution don't need any dependencies => erase the dependencies node
                // TODO : Check if it work
                if(empty($cur_pruned_dep))
                    unset($cur_pruned_dep);
				/*if (empty($new_struct['Packages'][$key][$key_dependencies[$i]])) {
					unset($new_struct['Packages'][$key][$key_dependencies[$i]]);
				}*/
                
            }
		}
        //print_r($pruned_pkg_node);
		return $pruned_pkg_node;
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$input_file = $input->getArgument('input');
        $input_target = $input->getArgument('target');
		$local = $input->getOption('local');
        
        // Get references of the command normalize
		$command = $this->getApplication()->find('normalize');
		$array_input = new ArrayInput(array('command' => 'normalize',
                                            'input' => $input_file,
                                            '--local' => $local)
        );
		$command->run($array_input, $output);

        // Launch logger module
        $this->logger = new ConsoleLogger($output);
		
        // "--local" or "-l" is set => generate pruned YAML for the CURRENT distribution
        if($local)
        {
            $this->getDist();
            $this->getApplication()->data['Packages'] = $this->prune_node($this->getApplication()->data['Packages']);
        }
        
        // If the "--local" option is not set, so we generate pruned YAML for each distrib
		else
        {
            // If input target is set, always compile for all version
            $target = array();
            
            if($input_target)
            {   
                foreach($input_target as $distrib_name) {
                    $distrib_name = ucfirst(strtolower($distrib_name)); //Normalize input
                    if(in_array($distrib_name, $this->getApplication()->conf['Distribution']))
                        array_push($target, $distrib_name);
                }
            }
            
            if(count($target) == 0)
                    $target = $this->getApplication()->conf['Distribution']; // Compile on every distribution
            
            // Generate prune packages node for each distribution targetted
			foreach($target as $distribution)
            {
                $this->getApplication()->dist_name = $distribution;
                // echo "PRUNING : $distribution\n";
                
                //Gere cas où la distribution n'a pas version
                if(!isset($this->getApplication()->conf[$distribution]['Version'])) {
                    $this->getApplication()->dist_version = null;
                    $this->getApplication()->data['To_build'][$distribution]['Packages'] = $this->prune_node($this->getApplication()->data['Packages']);
                    // print_r($this->getApplication()->data['Distributions'][$distribution]['Packages']);
                } else {
                    array_push($this->to_prune, current($this->getApplication()->conf[$distribution]['Version']));
                    foreach($this->to_prune as $cur_version)
                    {
                        $this->getApplication()->dist_version = $cur_version;
                        
                        // Add a node for each distributions/versions
                        // Foreach packages ? 
                        $this->getApplication()->data['To_build'][$distribution][$cur_version]['Packages'] = $this->prune_node($this->getApplication()->data['Packages']);
                        // print_r($this->getApplication()->data['Distributions'][$distribution][$cur_version]['Packages']);
                    }
                }
                
                // unset($this->to_prune);
                $this->to_prune = array();
			}
            
			// Removes the original field 'Packages', now it's useless <= is it usefull to unset ? For memory purpose I guess
			unset($this->getApplication()->data['Packages']);
            
            // print_r($this->getApplication()->data['To_build']);
		}

		// Optionnal argument
		/*$output_file = $input->getArgument('output');
		if ($output_file) {
			// Get references of the command write()
			$command = $this->getApplication()->find('write');
			$array_input = new ArrayInput(array('command' => 'write',
                                                'output' => $output_file)
            );
			$command->run($array_input, $output);
		}*/
	}
}
