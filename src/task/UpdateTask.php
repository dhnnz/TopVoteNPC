<?php
namespace dhnnz\TopVoteNPC\task;

use dhnnz\TopVoteNPC\Loader;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\Internet;

class UpdateTask extends AsyncTask
{
    public function __construct(protected string $key)
    {
    }

    public function onRun(): void
    {
        $success = true;
        $err = '';

        $url = Internet::getURL('https://minecraftpocket-servers.com/api/?object=servers&element=voters&month=current&format=json&limit=3&key=' . $this->key, 10, [], $err);

        if ($url === null) {
            $this->setResult(['success' => false, 'error' => "NULL", 'response' => false]);
            $success = false;
        }
        $raw = $url?->getBody();

        if (strpos($raw, 'Error:') !== false) {
            $err = trim(str_replace('Error:', '', $raw));
        }

        if ($err !== '') {
            $this->setResult(['success' => false, 'error' => $err, 'response' => empty($raw) === false ? $raw : 'null']);
            $success = false;
        }

        $data = json_decode($raw, true);

        if ($success && (!is_array($data) || empty($data))) {
            $this->setResult([
                'success' => false,
                'error' => 'No array could be created!',
                'response' => empty($raw) === false ? $raw : 'null'
            ]);
            $success = false;
        }

        if ($success) {
            $this->setResult(['success' => true, 'voters' => $data['voters']]);
        }
    }

    public function onCompletion(): void
    {
        /** @var Loader $inst */
        $inst = Loader::getInstance();

        if ($this->getResult()['success'] === true) {
            if ($inst->getVoters() !== $this->getResult()['voters']) {
                $inst->voters = $this->getResult()['voters'];
            }
        } else {
            $inst->getLogger()->error('Error: ' . $this->getResult()['error']);
        }
    }

}