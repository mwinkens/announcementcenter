<?php
/** TODO License
 * 
 */
namespace OCA\AnnouncementCenter\Command;

use OCA\AnnouncementCenter\Manager;
use OCA\AnnouncementCenter\Model\NotificationType;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Announce extends Command
{
    protected IUserManager $userManager;
    protected ITimeFactory $time;
    protected Manager $manager;
    protected NotificationType $notificationType;
    protected LoggerInterface $logger;
    public function __construct(IUserManager $userManager, ITimeFactory $time, Manager $manager, NotificationType $notificationType, LoggerInterface $logger)
    {
        parent::__construct();
        $this->userManager = $userManager;
        $this->time = $time;
        $this->manager = $manager;
        $this->notificationType = $notificationType;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('announcementcenter:announce')
            ->setDescription('Create an announcement')
            ->addArgument(
                'user',
                InputArgument::REQUIRED,
                'User who creates the announcement',
            )
            ->addArgument(
                'subject',
                InputArgument::REQUIRED,
                'User for whom the addressbook will be created',
            )
            ->addArgument(
                'message',
                InputArgument::REQUIRED,
                'message of the announcement (supports markdown)',
            )
            ->addOption(
                'activities',
                null,
                InputOption::VALUE_NONE,
                'Get notified over activities',
            )
            ->addOption(
                'notifications',
                null,
                InputOption::VALUE_NONE,
                'Get notified over nextclouds notifications',
            )
            ->addOption(
                'emails',
                null,
                InputOption::VALUE_NONE,
                'Notify users over email',
            )
            ->addOption(
                'comments',
                null,
                InputOption::VALUE_NONE,
                'Allow comments',
            )
            ->addOption(
                'schedule-time',
                's',
                InputOption::VALUE_OPTIONAL,
                'Publishing time of the announcement (see php strtotime)',
                null,
            )
            ->addOption(
                'delete-time',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Deletion time of the announcement (see php strtotime)',
                null,
            )
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'group to set send announcement to (default "everyone", multiple allowed)',
                ['everyone'],
            );
    }

    private function plainifyMessage(string $message)
    {
        # TODO use Parsedown or Markdownify here
        return $message;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // required
        $user = $input->getArgument('user');
        if (!$this->userManager->userExists($user)) {
            throw new \InvalidArgumentException("User <$user> in unknown.");
        }
        $subject = $input->getArgument('subject');
        $message = $input->getArgument('message');

        // options
        $groups = $input->getOption('group');

        // notification types
        $activities = $input->getOption('activities');
        $notifications = $input->getOption('notifications');
        $emails = $input->getOption('emails');
        $comments = $input->getOption('comments');

        // times
        $scheduleTime = $this->parseTimestamp($input->getOption('schedule-time'));
        $deleteTime = $this->parseTimestamp($input->getOption('delete-time'));

        // validation
        if ($scheduleTime && $deleteTime && $deleteTime < $scheduleTime) {
            throw new \InvalidArgumentException("Publishing time is after deletion time");
        }

        $plainMessage = $this->plainifyMessage($message);
        $notificationOptions = $this->notificationType->setNotificationTypes($activities, $notifications, $emails);

        $result = $this->manager->announce($subject, $message, $plainMessage, $user, $this->time->getTime(), $groups, $comments, $notificationOptions, $scheduleTime, $deleteTime);
        $output->writeln("Created announcement #" . $result->getId() . ": " . $result->getSubject());
        $this->logger->info('Admin ' . $user . ' posted a new announcement: "' . $result->getSubject() . '" over CLI');
        return $this::SUCCESS;
    }

    /**
     * Parses an arbitrary $argument into a timestamp
     * @param null|int|string $argument argument provided by CLI for a time
     *      Examples 1:
     *          '1711440621' a plain unix timestamp
     *      Examples 2 see strtotime (https://www.php.net/manual/de/function.strtotime.php): 
     *          'now', 10 September 200', '+1 day', 'tomorrow'
     * @return int|null a timestamp, returns null if $argument is null
     * @throws \InvalidArgumentException If the time could not be interpreted or the time is in the past
     */
    private function parseTimestamp(null|int|string $argument): int|null
    {
        if (is_null($argument)) {
            return null;
        } else if (is_numeric($argument)) {
            $timestamp = intval($argument);
        } else if (($convTime = strtotime($argument)) !== false) {
            $timestamp = $convTime;
        } else {
            throw new \InvalidArgumentException("Could not interprete time '" . $argument . "'");
        }

        if ($timestamp < $this->time->getTime()) {
            throw new \InvalidArgumentException("Time '" . $argument . "' is not allowed, because it's in the past");
        }
        return $timestamp;
    }
}